<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Payables\InvoiceFiles;
use App\Core\Payables\WorkflowException;
use CodeIgniter\Database\BaseConnection;

/**
 * The customer invoice PDF. Checking a file (extension, MIME type, %PDF magic bytes, active content, antivirus hook)
 * is delegated to InvoiceFiles so there is one set of upload rules; storage uses the receivables/ folder of the file store.
 */
final class ArFiles
{
    public const KEY_PREFIX = 'receivables/';

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @throws ReceivablesException
     */
    public function validate(string $path, string $originalName, ?int $size = null): void
    {
        try {
            (new InvoiceFiles($this->db))->validate($path, $originalName, $size);
        } catch (WorkflowException $e) {
            throw new ReceivablesException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Stores a validated PDF as the invoice's current file. Earlier files stay on disk (marked not current).
     *
     * @throws ReceivablesException
     */
    public function store(int $invoiceId, int $userId, string $tmpPath, string $originalName): int
    {
        $this->validate($tmpPath, $originalName);

        // Measure before storing: for an HTTP upload the file store moves the temporary file, so it is gone afterwards.
        $size = (int) filesize($tmpPath);
        $hash = hash_file('sha256', $tmpPath);

        $stored = bin2hex(random_bytes(16)) . '.pdf';
        try {
            service('fileStore')->put(self::KEY_PREFIX . $stored, $tmpPath);
        } catch (\RuntimeException $e) {
            throw new ReceivablesException($e->getMessage());
        }

        $clean = preg_replace('/[\x00-\x1F\x7F\\\\\/]+/', '', basename($originalName)) ?: 'invoice.pdf';

        $this->db->table('ar_files')->where('invoice_id', $invoiceId)->update(['is_current' => 0]);
        $this->db->table('ar_files')->insert([
            'invoice_id'    => $invoiceId,
            'original_name' => mb_substr($clean, 0, 255),
            'stored_name'   => $stored,
            'mime'          => 'application/pdf',
            'size_bytes'    => $size,
            'sha256'        => $hash,
            'is_current'    => 1,
            'uploaded_by'   => $userId,
            'uploaded_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(int $invoiceId): ?array
    {
        return $this->db->table('ar_files')->where(['invoice_id' => $invoiceId, 'is_current' => 1])->orderBy('id', 'DESC')->get()->getRowArray();
    }

    /**
     * A readable local path for a stored file, or null when it has been purged or is missing.
     *
     * @param array<string, mixed> $file
     */
    public function path(array $file): ?string
    {
        if (! empty($file['purged_at'])) {
            return null;
        }

        return service('fileStore')->localPath(self::key($file));
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function key(array $file): string
    {
        return self::KEY_PREFIX . basename((string) $file['stored_name']);
    }
}
