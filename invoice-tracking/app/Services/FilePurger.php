<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Removes server copies of invoice PDFs once they are no longer needed.
 *
 * A file is removed only when ALL of these hold for its invoice:
 *  - completed: SharePoint receipt is confirmed (sync_status = synced, with the item and file link stored), and
 *    the retention period has passed since the later of completion and confirmed sync;
 *  - rejected or cancelled: the retention period has passed since the workflow ended (it never goes to SharePoint).
 * Invoices that are not completed, rejected or cancelled (draft, in approval, returned, ...) are never touched, and
 * neither is a completed invoice that is pending, failed or not_configured. The database record, history and the
 * SharePoint link stay; only the file and its current/old copies go (invoice_files.purged_at is set).
 *
 * Supporting documents (invoice_documents) follow the same rules, and a document of a finished invoice is also kept
 * until its own copy in SharePoint is confirmed (its sync_status is synced).
 */
final class FilePurger
{
    public function __construct(private ?BaseConnection $db = null, private ?AuditLogger $audit = null)
    {
        $this->db    ??= db_connect();
        $this->audit ??= new AuditLogger($this->db);
    }

    /**
     * @return array{eligible: int, deleted: int, missing: int, failed: int, bytes: int}
     */
    public function run(bool $dryRun = false, ?int $days = null, ?\DateTimeImmutable $now = null): array
    {
        $days   ??= max(1, (int) setting('AppSettings.retentionDays'));
        $now    ??= new \DateTimeImmutable();
        $cutoff   = $now->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $result   = ['eligible' => 0, 'deleted' => 0, 'missing' => 0, 'failed' => 0, 'bytes' => 0];
        $store    = service('fileStore');

        $candidates = [];
        foreach ($this->candidates('invoice_files', $cutoff) as $f) {
            $candidates[] = ['table' => 'invoice_files', 'key' => InvoiceFiles::key($f), 'file' => $f];
        }
        foreach ($this->candidates('invoice_documents', $cutoff) as $f) {
            $candidates[] = ['table' => 'invoice_documents', 'key' => InvoiceDocuments::key($f), 'file' => $f];
        }

        foreach ($candidates as ['table' => $table, 'key' => $key, 'file' => $f]) {
            $result['eligible']++;
            if ($dryRun) {
                $result['bytes'] += (int) $f['size_bytes'];

                continue;
            }

            if ($store->exists($key)) {
                if (! $store->delete($key)) {
                    $result['failed']++;

                    continue;
                }
                $result['deleted']++;
                $result['bytes'] += (int) $f['size_bytes'];
            } else {
                $result['missing']++; // already gone: just record it
            }

            $this->db->table($table)->where('id', $f['id'])->update(['purged_at' => $now->format('Y-m-d H:i:s')]);
            $this->audit->log('file.purged', null, 'invoice', (int) $f['invoice_id'], [
                'file_id' => (int) $f['id'], 'sha256' => $f['sha256'], 'status' => $f['status'], 'sync_status' => $f['sync_status'],
            ] + ($table === 'invoice_documents' ? ['document' => true] : []));
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(string $table, string $cutoff): array
    {
        $docs = $table === 'invoice_documents';
        $rows = $this->db->table($table . ' f')
            ->select('f.id, f.invoice_id, f.stored_name, f.size_bytes, f.sha256, ' . ($docs ? 'f.sync_status AS doc_sync_status, ' : '') . 'i.status, i.sync_status, i.completed_at, i.synced_at, i.ended_at, i.sharepoint_item_id, i.sharepoint_url')
            ->join('invoices i', 'i.id = f.invoice_id')
            ->where('f.purged_at', null)
            ->whereIn('i.status', ['processed', 'paid', 'rejected', 'cancelled'])
            ->orderBy('f.id')
            ->get()->getResultArray();

        return array_values(array_filter($rows, static function (array $r) use ($cutoff, $docs): bool {
            if (! in_array($r['status'], ['processed', 'paid'], true)) {
                return ! empty($r['ended_at']) && $r['ended_at'] <= $cutoff;
            }

            // Finished (paid, or processed on a route with no pay step; completed_at is empty while an invoice still
            // waits to be paid): only with confirmed receipt, and never on a guess.
            if ($r['sync_status'] !== 'synced' || (empty($r['sharepoint_item_id']) && empty($r['sharepoint_url'])) || empty($r['synced_at']) || empty($r['completed_at'])) {
                return false;
            }
            if ($docs && $r['doc_sync_status'] !== 'synced') {
                return false;
            }

            return max($r['completed_at'], $r['synced_at']) <= $cutoff;
        }));
    }
}
