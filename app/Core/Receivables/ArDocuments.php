<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Payables\InvoiceDocuments;
use App\Core\Payables\WorkflowException;
use CodeIgniter\Database\BaseConnection;

/**
 * Supporting documents attached to a customer invoice while it is being approved. File checking is delegated to
 * InvoiceDocuments so there is one set of upload rules; storage uses the receivables/ folder of the file store.
 */
final class ArDocuments
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * Validates and stores a document. Returns the new row id.
     *
     * @throws ReceivablesException
     */
    public function store(int $invoiceId, int $userId, string $tmpPath, string $originalName, string $actedAs = 'normal', ?int $noteId = null): int
    {
        try {
            $mime = (new InvoiceDocuments($this->db))->validate($tmpPath, $originalName);
        } catch (WorkflowException $e) {
            throw new ReceivablesException($e->getMessage(), 0, $e);
        }

        // Measure before storing: an HTTP upload is moved by the file store.
        $size = (int) filesize($tmpPath);
        $hash = hash_file('sha256', $tmpPath);
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        try {
            service('fileStore')->put(ArFiles::KEY_PREFIX . $stored, $tmpPath);
        } catch (\RuntimeException $e) {
            throw new ReceivablesException($e->getMessage());
        }

        $this->db->table('ar_documents')->insert([
            'invoice_id'    => $invoiceId,
            'original_name' => mb_substr(InvoiceDocuments::cleanName($originalName), 0, 255),
            'stored_name'   => $stored,
            'mime'          => $mime,
            'size_bytes'    => $size,
            'sha256'        => $hash,
            'uploaded_by'   => $userId,
            'acted_as'      => $actedAs,
            'note_id'       => $noteId,
            'uploaded_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @return list<array<string, mixed>> oldest first
     */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->table('ar_documents d')
            ->select('d.*, u.display_name AS uploader_name, u.username AS uploader_username')
            ->join('inv_users u', 'u.id = d.uploaded_by', 'left')
            ->where('d.invoice_id', $invoiceId)->orderBy('d.id', 'ASC')->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $invoiceId, int $documentId): ?array
    {
        return $this->db->table('ar_documents')->where(['id' => $documentId, 'invoice_id' => $invoiceId])->get()->getRowArray();
    }

    /**
     * A readable local path, or null when purged or missing.
     *
     * @param array<string, mixed> $doc
     */
    public function path(array $doc): ?string
    {
        if (! empty($doc['purged_at'])) {
            return null;
        }

        return service('fileStore')->localPath(ArFiles::KEY_PREFIX . basename((string) $doc['stored_name']));
    }
}
