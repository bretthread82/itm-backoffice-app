<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Customer invoice events as platform notifications. Wording lives here; delivery (bell, email, preferences)
 * is NotificationService. High-priority invoices always email the recipients.
 */
final class ArNotifier
{
    /** Events that always email when the invoice is high priority. */
    public const PRIORITY_EVENTS = ['ar.submitted', 'ar.resubmitted', 'ar.returned_step'];

    private ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param list<int>            $userIds
     * @param array<string, mixed> $ctx     actor_id, actor_name, note, step_label, document_name, ...
     */
    public function dispatch(string $event, int $invoiceId, array $userIds, array $ctx = []): void
    {
        $invoice = $this->invoice($invoiceId);

        if ($invoice === null) {
            return;
        }

        $actorId = (int) ($ctx['actor_id'] ?? 0);
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0 && $id !== $actorId,
        )));

        if ($userIds === []) {
            return;
        }

        $priority       = self::isPriority($event, $invoice);
        [$title, $body] = self::describe($event, $invoice, $ctx);

        service('notifier')->send($userIds, $event, $title, $body, 'receivables/' . $invoiceId, ['invoice_id' => $invoiceId, 'priority' => $priority], $priority);
    }

    /**
     * Email-only notice to an address that is not a user (a team's group mailbox).
     *
     * @param array<string, mixed> $ctx
     */
    public function dispatchToAddress(string $event, int $invoiceId, string $email, array $ctx = []): void
    {
        $invoice = $this->invoice($invoiceId);
        $mailer  = service('mailer');

        if ($invoice === null || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $mailer->isConfigured()) {
            return;
        }

        [$title, $body] = self::describe($event, $invoice, $ctx);
        $mailer->send(strtolower($email), $title, view('emails/notification', ['title' => $title, 'body' => $body, 'url' => site_url('receivables/' . $invoiceId)]));
    }

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
        $no       = $invoice['invoice_number'] !== null && $invoice['invoice_number'] !== '' ? '#' . $invoice['invoice_number'] : '(no number)';
        $who      = (string) ($ctx['actor_name'] ?? 'Someone');
        $company  = (string) ($invoice['company_code'] ?? '');
        $note     = trim((string) ($ctx['note'] ?? ''));
        $noteTxt  = $note !== '' ? "\nNote: " . $note : '';
        $step     = (string) ($ctx['step_label'] ?? '');
        $stepTxt  = $step !== '' ? "Step: {$step}. " : '';
        $customer = $invoice['customer'] ? ' to ' . $invoice['customer'] : '';

        return match ($event) {
            'ar.submitted'     => ["Customer invoice {$no}{$customer} ({$company}) is waiting for your approval", $stepTxt . "Submitted by {$who}."],
            'ar.received'      => ["Your customer invoice {$no} ({$company}) was submitted", 'It is now with the approvers' . ($step !== '' ? " ({$step})" : '') . '.'],
            'ar.step_approved' => ["Customer invoice {$no} ({$company}) approved by {$who}", ($step !== '' ? "It is now with {$step}." : 'It has moved on.') . $noteTxt],
            'ar.issued'        => ["Customer invoice {$no}{$customer} ({$company}) was issued", "{$who} issued it" . (! empty($ctx['sent_to']) ? ' to ' . $ctx['sent_to'] : '') . '.' . $noteTxt],
            'ar.payment_received' => ["Payment of {$ctx['amount']} received on customer invoice {$no}{$customer} ({$company})", "{$who} recorded it. Still owed: {$ctx['outstanding']}."],
            'ar.paid'          => ["Customer invoice {$no}{$customer} ({$company}) was paid in full", "{$who} recorded the final payment of {$ctx['amount']}."],
            'ar.payment_reversed' => ["A payment of {$ctx['amount']} on customer invoice {$no}{$customer} ({$company}) was reversed", "{$who} reversed it. Still owed: {$ctx['outstanding']}." . $noteTxt],
            'ar.overdue'       => ["Customer invoice {$no}{$customer} ({$company}) is overdue", ($ctx['days_overdue'] ?? 0) . ' day(s) past the due date of ' . ($ctx['due_date'] ?? '') . '. Still owed: ' . ($ctx['outstanding'] ?? '') . '.'],
            'ar.rejected'      => ["Customer invoice {$no} ({$company}) was rejected by {$who}", 'You can edit it and resubmit, with a note explaining what changed.' . $noteTxt],
            'ar.returned'      => ["Customer invoice {$no} ({$company}) was returned for changes by {$who}", 'Please update it and resubmit, with a note explaining what changed.' . $noteTxt],
            'ar.resubmitted'   => ["Customer invoice {$no} ({$company}) was resubmitted and is waiting for you", "{$who} resubmitted it." . $noteTxt],
            'ar.returned_step' => ["Customer invoice {$no}{$customer} ({$company}) was returned to you by {$who}", $stepTxt . 'Please review it and approve again, or return or reject it.' . $noteTxt],
            'ar.override'      => ["{$who} (group-level) acted on customer invoice {$no} ({$company})", $stepTxt . 'This was done as a group-level action.' . $noteTxt],
            'ar.claimed'       => ["{$who} accepted customer invoice {$no} ({$company})", $stepTxt . 'It is no longer waiting for the rest of the team.'],
            'ar.released'      => ["{$who} released customer invoice {$no} ({$company}) back to the team", $stepTxt . 'Anyone on the team can now accept it.' . $noteTxt],
            'ar.document_added' => ["{$who} added a document to customer invoice {$no} ({$company})", 'Document: ' . (string) ($ctx['document_name'] ?? 'a file') . '.' . $noteTxt],
            'ar.notify_step'   => ["For your information: customer invoice {$no}{$customer} ({$company})", ($step !== '' ? "You are listed on step: {$step}. " : '') . 'No action is needed.'],
            default            => [(string) ($ctx['title'] ?? $event), (string) ($ctx['body'] ?? '')],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoice(int $invoiceId): ?array
    {
        return $this->db->table('ar_invoices i')
            ->select('i.id, i.invoice_number, i.customer, i.high_priority, c.code AS company_code')
            ->join('companies c', 'c.id = i.company_id')
            ->where('i.id', $invoiceId)->get()->getRowArray();
    }
}
