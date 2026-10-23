<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns database rows into API output. Every field is listed here on purpose: a column added to a
 * table later does not reach the API until someone chooses to expose it. Never emitted: the route
 * snapshot, SharePoint ids and links, stored file names and hashes, SSO identifiers, admin flags.
 */
final class ApiPresenter
{
    /** Money as a fixed two-decimal string so no client rounds it as a float. */
    public static function money(mixed $amount): ?string
    {
        return $amount === null || $amount === '' ? null : number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $r an invoice row with company_code, type_name and submitter (or submitter_name) joined
     *
     * @return array<string, mixed>
     */
    public static function invoice(array $r): array
    {
        helper('invoice');

        return [
            'id'                => (int) $r['id'],
            'invoice_number'    => $r['invoice_number'],
            'supplier'          => $r['supplier'],
            'company'           => ['id' => (int) $r['company_id'], 'code' => $r['company_code'] ?? null],
            'type'              => $r['type_name'] ?? null,
            'invoice_date'      => $r['invoice_date'],
            'due_date'          => $r['due_date'],
            'amount'            => self::money($r['amount']),
            'currency'          => $r['currency'],
            'po_reference'      => $r['po_reference'],
            'status'            => $r['status'],
            'status_label'      => invoice_status_label((string) $r['status']),
            'extra_approval'    => (bool) (int) ($r['extra_approval'] ?? 0),
            'high_priority'     => (bool) (int) ($r['high_priority'] ?? 0),
            'submitted_by'      => $r['submitter'] ?? $r['submitter_name'] ?? null,
            'submitted_at'      => $r['submitted_at'],
            'completed_at'      => $r['completed_at'],
            'processed_at'      => $r['processed_at'] ?? null,
            'paid_at'           => $r['paid_at'] ?? null,
            'payment_reference' => $r['payment_reference'] ?? null,
            'payment_date'      => $r['payment_date'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>                 $r        invoice row with joins
     * @param array<string, mixed>|null            $file     current invoice_files row
     * @param bool                                 $fileHere the PDF is still on the server
     * @param list<array<string, mixed>>           $steps    route steps from the snapshot
     * @param array<string, mixed>|null            $current  the step the invoice is waiting at
     * @param string                               $currentLabel
     * @param list<array<string, mixed>>           $documents supporting documents (InvoiceDocuments::forInvoice rows, optionally with "here": the file is still on the server)
     *
     * @return array<string, mixed>
     */
    public static function invoiceDetail(array $r, ?array $file, bool $fileHere, array $steps, ?array $current, string $currentLabel, array $documents = []): array
    {
        $engine = new WorkflowEngine();

        return self::invoice($r) + [
            'company_name'  => $r['company_name'] ?? null,
            'current_step'  => $current === null ? null : ['order' => (int) $current['order'], 'type' => $current['step_type'], 'label' => $currentLabel],
            'steps'         => array_map(static fn (array $s): array => [
                'order' => (int) $s['order'],
                'type'  => $s['step_type'],
                'label' => $engine->label($s),
            ], $steps),
            'file'          => $file === null ? null : [
                'name'        => (string) $file['original_name'],
                'size_bytes'  => (int) $file['size_bytes'],
                'uploaded_at' => $file['uploaded_at'],
                'available'   => $fileHere,
            ],
            'documents'     => array_map(static fn (array $d): array => [
                'id'          => (int) $d['id'],
                'name'        => (string) $d['original_name'],
                'mime'        => (string) $d['mime'],
                'size_bytes'  => (int) $d['size_bytes'],
                'uploaded_by' => ($d['uploader_name'] ?? '') !== '' ? $d['uploader_name'] : ($d['uploader_username'] ?? null),
                'uploaded_at' => $d['uploaded_at'],
                'available'   => (bool) ($d['here'] ?? empty($d['purged_at'])),
            ], $documents),
            'created_at'    => $r['created_at'],
            'updated_at'    => $r['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $e an event from InvoiceTimeline
     *
     * @return array<string, mixed>
     */
    public static function timelineEvent(array $e): array
    {
        $isAction = $e['type'] === 'action';

        return [
            'at'           => $e['at'],
            'type'         => $e['type'],
            'action'       => $isAction ? $e['action'] : null,
            'step_order'   => $isAction && $e['step_order'] !== null ? (int) $e['step_order'] : null,
            'actor'        => $e['actor'] ?? null,
            'on_behalf_of' => $isAction ? ($e['on_behalf_name'] ?? null) : null,
            'acted_as'     => $isAction ? $e['acted_as'] : null,
            'note'         => ($e['note_body'] ?? null) === null ? null : ['kind' => $e['note_kind'], 'body' => $e['note_body']],
        ];
    }

    /**
     * @param array<string, mixed> $r a row from ApprovalQueue
     *
     * @return array<string, mixed>
     */
    public static function queueItem(array $r): array
    {
        return self::invoice($r) + [
            'step_label'    => $r['step_label'],
            'acting_as'     => $r['acting_as'],
            'claim_state'   => $r['claim_state'] ?? null,
            'days_waiting'  => (int) $r['days_waiting'],
            'days_past_due' => (int) $r['days_past_due'],
        ];
    }

    /**
     * @param array<string, mixed> $r a row from ReportService::outstanding()
     *
     * @return array<string, mixed>
     */
    public static function outstandingRow(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'invoice_number' => $r['invoice_number'],
            'supplier'       => $r['supplier'],
            'company'        => ['id' => (int) $r['company_id'], 'code' => $r['company_code'], 'name' => $r['company_name']],
            'sub_group'      => $r['group_name'],
            'amount'         => self::money($r['amount']),
            'currency'       => $r['currency'],
            'due_date'       => $r['due_date'],
            'status'         => $r['status'],
            'step_label'     => $r['step_label'],
            'days_past_due'  => (int) $r['days_past_due'],
            'band'           => $r['band'],
            'days_waiting'   => (int) $r['days_waiting'],
        ];
    }

    /**
     * @param array<string, mixed> $agg ReportService::aggregate() output
     *
     * @return array<string, mixed>
     */
    public static function aggregate(array $agg): array
    {
        $bucket = static fn (array $b): array => ['count' => (int) $b['count'], 'amount' => array_map(static fn ($v): string => number_format((float) $v, 2, '.', ''), $b['amount'])];

        return [
            'total'     => $bucket($agg['total']),
            'overdue'   => $bucket($agg['overdue']),
            'critical'  => $bucket($agg['critical']),
            'bands'     => array_map($bucket, $agg['bands']),
            'companies' => array_values(array_map(static fn (array $c): array => [
                'company_id' => (int) $c['company_id'], 'code' => $c['code'], 'name' => $c['name'],
                'overdue' => (int) $c['overdue'], 'critical' => (int) $c['critical'], 'worst_days_past_due' => (int) $c['worst'],
            ] + $bucket($c), $agg['companies'])),
        ];
    }
}
