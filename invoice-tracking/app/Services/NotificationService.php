<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * One call per event: writes the in-app (bell) alert and queues + sends the email, honouring each
 * user's preferences. Email problems never propagate: a failed send stays in `notifications` with
 * its error and is retried by `php spark notifications:send`.
 */
final class NotificationService
{
    /** Events users can switch email off for. The bell is always on. */
    public const EVENTS = [
        'invoice.submitted'   => 'An invoice is waiting for my approval',
        'invoice.received'    => 'My invoice was submitted',
        'invoice.step_approved' => 'My invoice moved to the next step',
        'invoice.processed'   => 'My invoice was processed',
        'invoice.paid'        => 'My invoice was paid',
        'invoice.rejected'    => 'My invoice was rejected',
        'invoice.returned'    => 'My invoice was returned for changes',
        'invoice.resubmitted' => 'An invoice I approve was resubmitted',
        'invoice.returned_step' => 'An invoice was returned to me by the next approver',
        'invoice.override'    => 'A group-level user acted on my step or invoice',
        'invoice.notify_step' => 'I am copied on an invoice',
        'invoice.claimed'     => 'A team member accepted an invoice',
        'invoice.released'    => 'An invoice was released back to my team',
        'invoice.document_added' => 'A supporting document was added to my invoice',
        'invoice.reminder'    => 'An invoice has been waiting on me for too long',
        'invoice.escalated'   => 'An invoice has been stuck at a step for too long (group-level)',
        'sync.failed'        => 'An invoice could not be saved to SharePoint',
        'digest'            => 'Daily summary of outstanding invoices',
    ];

    private const MAX_ATTEMPTS = 5;

    public function __construct(private ?BaseConnection $db = null, private ?MailTransport $mail = null)
    {
        $this->db   ??= db_connect();
        $this->mail ??= service('mailer');
    }

    /**
     * @param list<int>            $userIds
     * @param array<string, mixed> $ctx     actor_id, actor_name, note, step_label, next_label, ...
     */
    public function dispatch(string $event, int $invoiceId, array $userIds, array $ctx = []): void
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null) {
            return;
        }

        $actorId = (int) ($ctx['actor_id'] ?? 0);
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0 && $id !== $actorId)));
        if ($userIds === []) {
            return;
        }

        $priority       = self::isPriority($event, $invoice);
        [$title, $body] = self::describe($event, $invoice, $ctx);
        $url            = 'invoices/' . $invoiceId;
        $emails         = $this->emailsFor($userIds);
        $now            = date('Y-m-d H:i:s');

        foreach ($userIds as $userId) {
            $this->db->table('user_notifications')->insert([
                'user_id' => $userId, 'invoice_id' => $invoiceId, 'event' => $event,
                'title' => $title, 'body' => $body, 'url' => $url, 'priority' => $priority ? 1 : 0, 'created_at' => $now,
            ]);

            // A high priority alert is emailed whatever the user's preference says.
            if (isset($emails[$userId]) && ($priority || $this->emailEnabled($userId, $event))) {
                $this->queueEmail($userId, $emails[$userId], $event, $invoiceId, $title, $body, $url);
            }
        }
    }

    /**
     * The once-a-day summary for one user (bell + email). Returns false when the user has switched
     * the digest off or has no one to tell.
     *
     * @param list<array{heading: string, items: list<array{text: string, url: string}>}> $sections
     */
    public function dispatchDigest(int $userId, string $title, array $sections): bool
    {
        $pref = $this->db->table('notification_prefs')->where(['user_id' => $userId, 'event' => 'digest'])->get()->getRowArray();
        if ($pref !== null && (int) $pref['daily_digest'] === 0) {
            return false;
        }

        $count = array_sum(array_map(static fn (array $s): int => count($s['items']), $sections));
        $text  = $count . ' invoice' . ($count === 1 ? '' : 's') . ' need' . ($count === 1 ? 's' : '') . ' attention.';

        $this->db->table('user_notifications')->insert([
            'user_id' => $userId, 'invoice_id' => null, 'event' => 'digest', 'title' => $title, 'body' => $text,
            'url' => 'approvals', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $emails = $this->emailsFor([$userId]);
        if (isset($emails[$userId]) && ($pref === null || (int) $pref['email'] === 1)) {
            $html = view('emails/digest', ['title' => $title, 'sections' => $sections, 'url' => site_url('approvals')]);
            $this->db->table('notifications')->insert([
                'user_id' => $userId, 'invoice_id' => null, 'event' => 'digest', 'to_email' => $emails[$userId],
                'subject' => mb_substr($title, 0, 255), 'body' => $html, 'status' => 'pending', 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->attempt((int) $this->db->insertID());
        }

        return true;
    }

    public function digestSentToday(int $userId): bool
    {
        return $this->db->table('user_notifications')->where(['user_id' => $userId, 'event' => 'digest'])
            ->where('created_at >=', date('Y-m-d 00:00:00'))->countAllResults() > 0;
    }

    /**
     * Email-only notice to an address that is not a user (a team's group mailbox or an outside party).
     *
     * @param array<string, mixed> $ctx
     */
    public function dispatchToAddress(string $event, int $invoiceId, string $email, array $ctx = []): void
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        [$title, $body] = self::describe($event, $invoice, $ctx);
        $this->queueEmail(null, strtolower($email), $event, $invoiceId, $title, $body, 'invoices/' . $invoiceId);
    }

    /**
     * Sends anything pending or failed (and not yet given up on).
     *
     * @return array{sent: int, failed: int}
     */
    public function sendPending(int $limit = 50): array
    {
        $rows = $this->db->table('notifications')
            ->whereIn('status', ['pending', 'failed'])->where('attempts <', self::MAX_ATTEMPTS)
            ->orderBy('id')->limit($limit)->get()->getResultArray();

        $sent = $failed = 0;
        foreach ($rows as $row) {
            $this->attempt((int) $row['id']) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function unreadCount(int $userId): int
    {
        return $this->db->table('user_notifications')->where('user_id', $userId)->where('read_at', null)->countAllResults();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $userId, int $limit = 8, bool $unreadOnly = false): array
    {
        $builder = $this->db->table('user_notifications')->where('user_id', $userId);
        if ($unreadOnly) {
            $builder->where('read_at', null);
        }

        return $builder->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    public function markRead(int $userId, int $id): void
    {
        $this->db->table('user_notifications')->where(['id' => $id, 'user_id' => $userId, 'read_at' => null])->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->table('user_notifications')->where(['user_id' => $userId, 'read_at' => null])->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function emailEnabled(int $userId, string $event): bool
    {
        $pref = $this->db->table('notification_prefs')->where(['user_id' => $userId, 'event' => $event])->get()->getRowArray();

        return $pref === null || (int) $pref['email'] === 1;
    }

    /**
     * "Waiting on you" alerts that cannot be switched off for a high priority invoice: it arrived at
     * your step (new, resubmitted, returned to you) or has been waiting too long.
     */
    public const PRIORITY_EVENTS = ['invoice.submitted', 'invoice.resubmitted', 'invoice.returned_step', 'invoice.reminder', 'invoice.escalated'];

    /**
     * @param array<string, mixed> $invoice
     */
    public static function isPriority(string $event, array $invoice): bool
    {
        return ! empty($invoice['high_priority']) && in_array($event, self::PRIORITY_EVENTS, true);
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $ctx
     *
     * @return array{0: string, 1: string} title, body (plain text)
     */
    public static function describe(string $event, array $invoice, array $ctx): array
    {
        [$title, $body] = self::describePlain($event, $invoice, $ctx);

        return self::isPriority($event, $invoice)
            ? ['HIGH PRIORITY: ' . $title, 'This is a high priority invoice. ' . $body]
            : [$title, $body];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $ctx
     *
     * @return array{0: string, 1: string}
     */
    private static function describePlain(string $event, array $invoice, array $ctx): array
    {
        $no      = $invoice['invoice_number'] !== null && $invoice['invoice_number'] !== '' ? '#' . $invoice['invoice_number'] : '(no number)';
        $who     = (string) ($ctx['actor_name'] ?? 'Someone');
        $company = (string) ($invoice['company_code'] ?? '');
        $note    = trim((string) ($ctx['note'] ?? ''));
        $noteTxt = $note !== '' ? "\nNote: " . $note : '';
        $step    = (string) ($ctx['step_label'] ?? '');
        $supplier = $invoice['supplier'] ? ' from ' . $invoice['supplier'] : '';

        return match ($event) {
            'invoice.submitted'     => ["Invoice {$no}{$supplier} ({$company}) is waiting for your approval", ($step !== '' ? "Step: {$step}. " : '') . "Submitted by {$who}."],
            'invoice.received'      => ["Your invoice {$no} ({$company}) was submitted", 'It is now with the approvers' . ($step !== '' ? " ({$step})" : '') . '.'],
            'invoice.step_approved' => ["Invoice {$no} ({$company}) approved by {$who}", ($step !== '' ? "It is now with {$step}." : 'It has moved on.') . $noteTxt],
            'invoice.processed'     => ["Invoice {$no} ({$company}) was processed", "{$who} processed it." . ($step !== '' ? " It is now with {$step}." : '') . $noteTxt],
            'invoice.paid'          => ["Invoice {$no} ({$company}) was paid", "{$who} recorded the payment." . $noteTxt],
            'invoice.completed'     => ["Invoice {$no} ({$company}) is fully approved", "{$who} completed the final step." . $noteTxt],   // notifications sent before processed/paid existed
            'invoice.rejected'      => ["Invoice {$no} ({$company}) was rejected by {$who}", 'You can edit it and resubmit, with a note explaining what changed.' . $noteTxt],
            'invoice.returned'      => ["Invoice {$no} ({$company}) was returned for changes by {$who}", 'Please update it and resubmit, with a note explaining what changed.' . $noteTxt],
            'invoice.resubmitted'   => ["Invoice {$no} ({$company}) was resubmitted and is waiting for you", "{$who} resubmitted it." . $noteTxt],
            'invoice.returned_step' => ["Invoice {$no}{$supplier} ({$company}) was returned to you by {$who}", ($step !== '' ? "Step: {$step}. " : '') . 'Please review it and approve again, or return or reject it.' . $noteTxt],
            'invoice.override'      => ["{$who} (group-level) acted on invoice {$no} ({$company})", ($step !== '' ? "Step: {$step}. " : '') . 'This was done as a group-level action.' . $noteTxt],
            'invoice.claimed'       => ["{$who} accepted invoice {$no} ({$company})", ($step !== '' ? "Step: {$step}. " : '') . 'It is no longer waiting for the rest of the team.'],
            'invoice.released'      => ["{$who} released invoice {$no} ({$company}) back to the team", ($step !== '' ? "Step: {$step}. " : '') . 'Anyone on the team can now accept it.' . $noteTxt],
            'invoice.document_added' => ["{$who} added a document to invoice {$no} ({$company})", 'Document: ' . (string) ($ctx['document_name'] ?? 'a file') . '.' . $noteTxt],
            'invoice.notify_step'   => ["For your information: invoice {$no}{$supplier} ({$company})", ($step !== '' ? "You are listed on step: {$step}. " : '') . 'No action is needed.'],
            'invoice.reminder'      => ["Reminder: invoice {$no}{$supplier} ({$company}) has been waiting {$ctx['days']} days", ($step !== '' ? "Step: {$step}. " : '') . "This step is expected to take {$ctx['sla_days']} days. Please action it or pass it on."],
            'invoice.escalated'     => ["Escalation: invoice {$no}{$supplier} ({$company}) has been stuck {$ctx['days']} days", ($step !== '' ? "Step: {$step}. " : '') . "It is well past the {$ctx['sla_days']}-day limit for this step. You can act on it as a group-level user (a reason is required)."],
            'sync.failed'           => ["Invoice {$no} ({$company}) could not be saved to SharePoint", (string) ($ctx['error'] ?? 'See the SharePoint sync screen.')],
            default                 => [(string) ($ctx['title'] ?? $event), (string) ($ctx['body'] ?? '')],
        };
    }

    private function queueEmail(?int $userId, string $to, string $event, ?int $invoiceId, string $title, string $body, string $url): void
    {
        $html = view('emails/notification', ['title' => $title, 'body' => $body, 'url' => site_url($url)]);

        $this->db->table('notifications')->insert([
            'user_id' => $userId, 'invoice_id' => $invoiceId, 'event' => $event, 'to_email' => $to,
            'subject' => mb_substr($title, 0, 255), 'body' => $html, 'status' => 'pending', 'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->attempt((int) $this->db->insertID());
    }

    private function attempt(int $id): bool
    {
        $row = $this->db->table('notifications')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return false;
        }

        try {
            $result = $this->mail->send(new MailMessage(
                (string) $row['to_email'],
                (string) $row['subject'],
                (string) $row['body'],
                (string) setting('AppSettings.mailFromEmail'),
                (string) setting('AppSettings.mailFromName'),
                (string) setting('AppSettings.mailReplyTo'),
            ));
        } catch (Throwable $e) {
            $result = \App\Services\Mail\MailResult::failed($e->getMessage());
        }

        $this->db->table('notifications')->where('id', $id)->update([
            'status'              => $result->ok ? 'sent' : 'failed',
            'attempts'            => (int) $row['attempts'] + 1,
            'sendgrid_message_id' => $result->messageId,
            'error'               => $result->ok ? null : mb_substr((string) $result->error, 0, 1000),
            'sent_at'             => $result->ok ? date('Y-m-d H:i:s') : null,
        ]);

        if (! $result->ok) {
            log_message('error', 'Email to {to} failed: {error}', ['to' => $row['to_email'], 'error' => $result->error]);
        }

        return $result->ok;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, string> user id => email, for active users that have one
     */
    private function emailsFor(array $userIds): array
    {
        $rows = $this->db->table('users u')
            ->select("u.id, (SELECT i.secret FROM auth_identities i WHERE i.user_id = u.id AND i.type = 'email_password' LIMIT 1) AS email", false)
            ->whereIn('u.id', $userIds)->where('u.active', 1)->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            if (! empty($r['email'])) {
                $out[(int) $r['id']] = strtolower((string) $r['email']);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoice(int $invoiceId): ?array
    {
        return $this->db->table('invoices i')
            ->select('i.id, i.invoice_number, i.supplier, i.high_priority, c.code AS company_code')
            ->join('companies c', 'c.id = i.company_id')
            ->where('i.id', $invoiceId)->get()->getRowArray();
    }
}
