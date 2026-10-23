<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

use App\Services\AuditLogger;
use App\Services\InvoiceDocuments;
use App\Services\InvoiceFiles;
use App\Services\NotificationService;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Sends processed invoices to SharePoint and keeps the queue honest.
 *
 * Queue states: pending (waiting, or waiting to retry), syncing (claimed by a running job),
 * synced, failed (gave up after MAX_ATTEMPTS: needs an admin), not_configured (no usable connection;
 * kept, and picked up automatically when one is switched on).
 *
 * Guarantees: nothing is sent unless the company's connection is enabled and complete; each invoice
 * is claimed atomically so two cron runs cannot send it twice; progress (file stored, item created)
 * is saved the moment each step succeeds, so a retry never repeats a finished step.
 */
final class SyncService
{
    public const MAX_ATTEMPTS = 8;

    /** A claimed row older than this is treated as abandoned by a crashed job. */
    private const STALE_AFTER = 1800;

    public function __construct(
        private ?BaseConnection $db = null,
        private ?SharePointClient $client = null,
        private ?Connections $connections = null,
        private ?NotificationService $notifier = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->db          ??= db_connect();
        $this->client      ??= service('sharepoint');
        $this->connections ??= new Connections($this->db);
        $this->notifier    ??= new NotificationService($this->db);
        $this->audit       ??= new AuditLogger($this->db);
    }

    /** Minutes to wait before the next attempt, after the Nth failure (5, 10, 20, 40, ... capped at 6 hours). */
    public static function backoffMinutes(int $attempts): int
    {
        return min(360, 5 * (2 ** max(0, $attempts - 1)));
    }

    /**
     * Processes everything that is due.
     *
     * @return array{processed: int, synced: int, retrying: int, failed: int, not_configured: int, skipped: int}
     */
    public function run(int $limit = 20): array
    {
        $now   = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - self::STALE_AFTER);

        $rows = $this->db->table('sync_queue')->select('invoice_id')
            ->groupStart()
                ->whereIn('status', ['pending', 'not_configured'])
                ->groupStart()->where('next_attempt_at', null)->orWhere('next_attempt_at <=', $now)->groupEnd()
            ->groupEnd()
            ->orGroupStart()->where('status', 'syncing')->where('updated_at <', $stale)->groupEnd()
            ->orderBy('id')->limit($limit)->get()->getResultArray();

        $out = ['processed' => 0, 'synced' => 0, 'retrying' => 0, 'failed' => 0, 'not_configured' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $outcome = $this->process((int) $r['invoice_id']);
            $out['processed']++;
            $key = match ($outcome) {
                'synced' => 'synced', 'retry' => 'retrying', 'failed' => 'failed', 'not_configured' => 'not_configured', default => 'skipped',
            };
            $out[$key]++;
        }

        $this->syncDocuments(); // documents added to invoices that were filed earlier

        return $out;
    }

    /**
     * Admin action: put a failed (or waiting) invoice back in the queue and try again now.
     */
    public function retry(int $invoiceId, int $actorId): string
    {
        $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->whereIn('status', ['failed', 'pending', 'not_configured'])->update([
            'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit->log('sharepoint.retry', $actorId, 'invoice', $invoiceId);

        return $this->process($invoiceId);
    }

    /**
     * @return string synced|retry|failed|not_configured|skipped
     */
    public function process(int $invoiceId): string
    {
        $inv = $this->db->table('invoices i')
            ->select('i.*, c.code AS company_code, c.name AS company_name, t.name AS type_name, u.display_name AS submitter_name')
            ->join('companies c', 'c.id = i.company_id')->join('invoice_types t', 't.id = i.invoice_type_id')
            ->join('users u', 'u.id = i.submitted_by', 'left')
            ->where('i.id', $invoiceId)->get()->getRowArray();

        if ($inv === null || ! in_array($inv['status'], ['processed', 'paid'], true)) {
            return 'skipped';
        }

        $connection = $this->connections->forCompany((int) $inv['company_id']);
        if ($connection === null) {
            $this->setState($invoiceId, 'not_configured', null);

            return 'not_configured'; // nothing is sent while there is no usable connection
        }

        if (! $this->claim($invoiceId, (int) $connection['id'])) {
            return 'skipped'; // another job has it
        }

        $files = new InvoiceFiles($this->db);
        $file  = $files->current($invoiceId);
        $path  = $file !== null ? $files->path($file) : null;
        if ($file === null || $path === null) {
            return $this->giveUp($inv, 'The invoice PDF is no longer on the server, so it cannot be sent.');
        }

        try {
            $c = $this->connections->forClient($connection);

            $this->client->store(
                $c,
                $this->payload($inv),
                $path,
                $this->fileName($inv),
                $inv['sharepoint_url'] ?: null,
                $inv['sharepoint_item_id'] ?: null,
                function (string $stage, string $value) use ($invoiceId): void {
                    // Saved immediately, so a later failure never repeats a step that already worked.
                    $this->db->table('invoices')->where('id', $invoiceId)->update($stage === 'file' ? ['sharepoint_url' => $value] : ['sharepoint_item_id' => $value]);
                },
            );
        } catch (Throwable $e) {
            return $this->failed($inv, $e instanceof SharePointException ? $e->getMessage() : 'Unexpected error: ' . mb_substr($e->getMessage(), 0, 300));
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('invoices')->where('id', $invoiceId)->update(['sync_status' => 'synced', 'synced_at' => $now, 'updated_at' => $now]);
        $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->set('attempts', 'attempts + 1', false)->update([
            'status' => 'synced', 'next_attempt_at' => null, 'last_error' => null, 'updated_at' => $now,
        ]);
        $this->audit->log('sharepoint.synced', null, 'invoice', $invoiceId, ['connection' => (int) $connection['id']]);

        $this->syncDocuments($invoiceId);

        return 'synced';
    }

    /**
     * Files supporting documents next to the invoice PDF. Only for invoices whose PDF is already in SharePoint,
     * and only through a usable connection. A document that fails stays pending and is tried again on the next
     * run; it never changes the invoice's own sync state. Documents added after the invoice was filed are
     * picked up here too.
     *
     * @return array{synced: int, failed: int}
     */
    public function syncDocuments(?int $invoiceId = null): array
    {
        $b = $this->db->table('invoice_documents d')
            ->select('d.*')
            ->join('invoices i', 'i.id = d.invoice_id')
            ->where('d.sync_status', 'pending')->where('d.purged_at', null)
            ->whereIn('i.status', ['processed', 'paid'])->where('i.sync_status', 'synced');
        if ($invoiceId !== null) {
            $b->where('d.invoice_id', $invoiceId);
        }
        $docs = $b->orderBy('d.id')->limit(50)->get()->getResultArray();

        $out = ['synced' => 0, 'failed' => 0];
        $docStore = new InvoiceDocuments($this->db);
        $clients  = [];
        foreach ($docs as $d) {
            $id  = (int) $d['invoice_id'];
            $inv = $this->db->table('invoices i')
                ->select('i.*, c.code AS company_code, c.name AS company_name, t.name AS type_name, u.display_name AS submitter_name')
                ->join('companies c', 'c.id = i.company_id')->join('invoice_types t', 't.id = i.invoice_type_id')
                ->join('users u', 'u.id = i.submitted_by', 'left')
                ->where('i.id', $id)->get()->getRowArray();
            if ($inv === null) {
                continue;
            }

            if (! array_key_exists($id, $clients)) {
                $connection  = $this->connections->forCompany((int) $inv['company_id']);
                $clients[$id] = $connection !== null ? $this->connections->forClient($connection) : null;
            }
            if ($clients[$id] === null) {
                continue; // no usable connection: nothing is sent, and it is picked up when one is switched on
            }

            $path = $docStore->path($d);
            if ($path === null) {
                $this->db->table('invoice_documents')->where('id', $d['id'])->update(['sync_status' => 'failed']);
                $out['failed']++;
                log_message('warning', 'Supporting document ' . $d['id'] . ' is no longer on the server, so it cannot be sent to SharePoint.');

                continue;
            }

            try {
                $url = $this->client->storeFile($clients[$id], $this->payload($inv), $path, $this->documentName($inv, $d), (string) $d['mime']);
            } catch (Throwable $e) {
                $out['failed']++;
                log_message('warning', 'Supporting document ' . $d['id'] . ' could not be sent to SharePoint: ' . mb_substr($e->getMessage(), 0, 300));

                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('invoice_documents')->where('id', $d['id'])->update([
                'sync_status' => 'synced', 'sharepoint_url' => mb_substr($url, 0, 1000), 'synced_at' => $now,
            ]);
            $this->audit->log('sharepoint.document_synced', null, 'invoice', $id, ['document' => (int) $d['id']]);
            $out['synced']++;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $doc
     */
    private function documentName(array $inv, array $doc): string
    {
        $stem = substr($this->fileName($inv), 0, -4);                     // the PDF's name without .pdf
        $ext  = strtolower(pathinfo((string) $doc['original_name'], PATHINFO_EXTENSION));
        $orig = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string) $doc['original_name'], PATHINFO_FILENAME)), '_.');

        return $stem . '_doc' . (int) $doc['id'] . '_' . mb_substr($orig !== '' ? $orig : 'document', 0, 60) . '.' . $ext;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Atomically takes the row. False if someone else holds it (and it is not stale).
     */
    private function claim(int $invoiceId, int $connectionId): bool
    {
        $now   = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - self::STALE_AFTER);

        $this->db->table('sync_queue')->where('invoice_id', $invoiceId)
            ->groupStart()
                ->whereIn('status', ['pending', 'not_configured'])
                ->orGroupStart()->where('status', 'syncing')->where('updated_at <', $stale)->groupEnd()
            ->groupEnd()
            ->update(['status' => 'syncing', 'connection_id' => $connectionId, 'updated_at' => $now]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function failed(array $inv, string $error): string
    {
        $invoiceId = (int) $inv['id'];
        $queue     = $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->get()->getRowArray();
        $attempts  = (int) ($queue['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->update(['attempts' => $attempts]);

            return $this->giveUp($inv, $error);
        }

        $next = date('Y-m-d H:i:s', time() + self::backoffMinutes($attempts) * 60);
        $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->update([
            'status' => 'pending', 'attempts' => $attempts, 'next_attempt_at' => $next, 'last_error' => mb_substr($error, 0, 1000), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('invoices')->where('id', $invoiceId)->update(['sync_status' => 'pending']);
        $this->recordConnectionError($queue['connection_id'] ?? null, $error);

        return 'retry';
    }

    /**
     * Stops retrying and tells the administrators once.
     *
     * @param array<string, mixed> $inv
     */
    private function giveUp(array $inv, string $error): string
    {
        $invoiceId = (int) $inv['id'];
        $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->update([
            'status' => 'failed', 'next_attempt_at' => null, 'last_error' => mb_substr($error, 0, 1000), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('invoices')->where('id', $invoiceId)->update(['sync_status' => 'failed']);
        $this->audit->log('sharepoint.failed', null, 'invoice', $invoiceId, ['error' => mb_substr($error, 0, 300)]);

        $admins = array_map('intval', array_column($this->db->table('users')->select('id')->where(['is_admin' => 1, 'active' => 1])->get()->getResultArray(), 'id'));
        $this->notifier->dispatch('sync.failed', $invoiceId, $admins, ['error' => $error, 'actor_id' => 0]);

        return 'failed';
    }

    private function setState(int $invoiceId, string $status, ?string $error): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->whereIn('status', ['pending', 'not_configured'])->update([
            'status' => $status, 'last_error' => $error, 'updated_at' => $now,
        ]);
        $this->db->table('invoices')->where('id', $invoiceId)->whereIn('sync_status', ['pending', 'not_configured'])->update(['sync_status' => $status]);
    }

    private function recordConnectionError(mixed $connectionId, string $error): void
    {
        if ($connectionId) {
            $this->db->table('sharepoint_connections')->where('id', (int) $connectionId)->update(['last_error' => mb_substr($error, 0, 1000)]);
        }
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function fileName(array $inv): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', ($inv['invoice_number'] ?: 'invoice') . '_' . ($inv['supplier'] ?: '')), '_.');

        return mb_substr($base, 0, 120) . '_' . (int) $inv['id'] . '.pdf';
    }

    /**
     * @param array<string, mixed> $inv
     *
     * @return array<string, mixed>
     */
    private function payload(array $inv): array
    {
        $approvals = $this->db->query(
            "SELECT a.acted_at, a.step_order, a.acted_as, u.display_name AS actor, o.display_name AS for_name
             FROM invoice_actions a JOIN users u ON u.id = a.acted_by LEFT JOIN users o ON o.id = a.on_behalf_of
             WHERE a.invoice_id = ? AND a.action = 'approve' ORDER BY a.id",
            [(int) $inv['id']],
        )->getResultArray();

        $lines = array_map(static fn (array $a): string => $a['actor']
            . ($a['acted_as'] === 'group_level' ? ' (group-level' . ($a['for_name'] ? ' for ' . $a['for_name'] : '') . ')' : ($a['acted_as'] === 'delegate' ? ' (delegate for ' . ($a['for_name'] ?? '?') . ')' : ''))
            . ' on ' . substr((string) $a['acted_at'], 0, 10), $approvals);

        return [
            'invoice_id'       => (int) $inv['id'],
            'invoice_number'   => $inv['invoice_number'],
            'supplier'         => $inv['supplier'],
            'amount'           => $inv['amount'],
            'currency'         => $inv['currency'],
            'invoice_date'     => $inv['invoice_date'],
            'due_date'         => $inv['due_date'],
            'po_reference'     => $inv['po_reference'],
            'company_code'     => $inv['company_code'],
            'company_name'     => $inv['company_name'],
            'invoice_type'     => $inv['type_name'],
            'status'           => 'Approved',
            'submitted_by'     => $inv['submitter_name'],
            'submitted_at'     => $inv['submitted_at'],
            'completed_at'     => $inv['completed_at'],
            'approval_summary' => implode('; ', $lines),
            'app_url'          => site_url('invoices/' . (int) $inv['id']),
        ];
    }
}
