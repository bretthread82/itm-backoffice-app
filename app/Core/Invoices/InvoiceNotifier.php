<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Invoice events as platform notifications. Wording lives here; delivery (bell, email, preferences)
 * is NotificationService. High-priority invoices always email the recipients.
 * Reminder, escalation, digest and delivery-retry events are phase 2.
 */
final class InvoiceNotifier
{
    /** Events that always email when the invoice is high priority. */
    public const PRIORITY_EVENTS = ['invoice.submitted', 'invoice.resubmitted', 'invoice.returned_step'];

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

        service('notifier')->send($userIds, $event, $title, $body, 'invoices/' . $invoiceId, ['invoice_id' => $invoiceId, 'priority' => $priority], $priority);
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
        $mailer->send(strtolower($email), $title, view('emails/notification', ['title' => $title, 'body' => $body, 'url' => site_url('invoices/' . $invoiceId)]));
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
        $supplier = $invoice['supplier'] ? ' from ' . $invoice['supplier'] : '';

        return match ($event) {
            'invoice.submitted'     => ["Invoice {$no}{$supplier} ({$company}) is waiting for your approval", $stepTxt . "Submitted by {$who}."],
            'invoice.received'      => ["Your invoice {$no} ({$company}) was submitted", 'It is now with the approvers' . ($step !== '' ? " ({$step})" : '') . '.'],
            'invoice.step_approved' => ["Invoice {$no} ({$company}) approved by {$who}", ($step !== '' ? "It is now with {$step}." : 'It has moved on.') . $noteTxt],
            'invoice.processed'     => ["Invoice {$no} ({$company}) was processed", "{$who} processed it." . ($step !== '' ? " It is now with {$step}." : '') . $noteTxt],
            'invoice.paid'          => ["Invoice {$no} ({$company}) was paid", "{$who} recorded the payment." . $noteTxt],
            'invoice.rejected'      => ["Invoice {$no} ({$company}) was rejected by {$who}", 'You can edit it and resubmit, with a note explaining what changed.' . $noteTxt],
            'invoice.returned'      => ["Invoice {$no} ({$company}) was returned for changes by {$who}", 'Please update it and resubmit, with a note explaining what changed.' . $noteTxt],
            'invoice.resubmitted'   => ["Invoice {$no} ({$company}) was resubmitted and is waiting for you", "{$who} resubmitted it." . $noteTxt],
            'invoice.returned_step' => ["Invoice {$no}{$supplier} ({$company}) was returned to you by {$who}", $stepTxt . 'Please review it and approve again, or return or reject it.' . $noteTxt],
            'invoice.override'      => ["{$who} (group-level) acted on invoice {$no} ({$company})", $stepTxt . 'This was done as a group-level action.' . $noteTxt],
            'invoice.claimed'       => ["{$who} accepted invoice {$no} ({$company})", $stepTxt . 'It is no longer waiting for the rest of the team.'],
            'invoice.released'      => ["{$who} released invoice {$no} ({$company}) back to the team", $stepTxt . 'Anyone on the team can now accept it.' . $noteTxt],
            'invoice.document_added' => ["{$who} added a document to invoice {$no} ({$company})", 'Document: ' . (string) ($ctx['document_name'] ?? 'a file') . '.' . $noteTxt],
            'invoice.notify_step'   => ["For your information: invoice {$no}{$supplier} ({$company})", ($step !== '' ? "You are listed on step: {$step}. " : '') . 'No action is needed.'],
            default                 => [(string) ($ctx['title'] ?? $event), (string) ($ctx['body'] ?? '')],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoice(int $invoiceId): ?array
    {
        return $this->db->table('inv_invoices i')
            ->select('i.id, i.invoice_number, i.supplier, i.high_priority, c.code AS company_code')
            ->join('companies c', 'c.id = i.company_id')
            ->where('i.id', $invoiceId)->get()->getRowArray();
    }
}
