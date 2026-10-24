<?php

declare(strict_types=1);

namespace App\Core\Payables;

use CodeIgniter\Database\BaseConnection;

/**
 * Supporting documents (quotes, purchase orders, emails, spreadsheets) attached to an invoice while it is
 * being approved. Each type is checked by extension, content type and file signature; Office files are
 * refused if they carry macros or embedded objects. Stored under a random name through the file store and
 * only served by a controller that checks access.
 */
final class InvoiceDocuments
{
    public const KEY_PREFIX = 'documents/';

    /** extension => allowed finfo MIME types (the first is the one stored and served). */
    private const TYPES = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'eml'  => ['message/rfc822', 'text/plain'],
        'msg'  => ['application/vnd.ms-outlook', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
    ];

    /** Types a browser may show inline; everything else is downloaded. */
    private const INLINE = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public static function directory(): string
    {
        return WRITEPATH . 'uploads/documents';
    }

    public static function acceptedExtensions(): string
    {
        return implode(', ', array_map('strtoupper', array_keys(self::TYPES)));
    }

    /** Value for an <input accept="..."> attribute. */
    public static function acceptAttribute(): string
    {
        return '.' . implode(',.', array_keys(self::TYPES));
    }

    public static function isInline(string $mime): bool
    {
        return in_array($mime, self::INLINE, true);
    }

    /**
     * @throws WorkflowException
     */
    public function validate(string $path, string $originalName, ?int $size = null): string
    {
        $size ??= (int) @filesize($path);
        $maxMb = max(1, (int) setting('InvoiceSettings.maxUploadMb'));

        if (! is_file($path) || $size <= 0) {
            throw new WorkflowException('The file is empty or could not be read.');
        }
        if ($size > $maxMb * 1024 * 1024) {
            throw new WorkflowException('The file is larger than ' . $maxMb . ' MB.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$ext])) {
            throw new WorkflowException('That file type is not allowed. You can attach ' . self::acceptedExtensions() . ' files.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, self::TYPES[$ext], true)) {
            throw new WorkflowException('That file does not look like a real ' . strtoupper($ext) . ' file.');
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);
        $ok   = match ($ext) {
            'pdf'         => str_starts_with($head, '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png'         => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'docx', 'xlsx' => str_starts_with($head, "PK\x03\x04"),
            'msg'         => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'eml'         => ! str_contains((string) file_get_contents($path, false, null, 0, 4096), "\0"),
            default       => false,
        };
        if (! $ok) {
            throw new WorkflowException('That file does not look like a real ' . strtoupper($ext) . ' file.');
        }

        if ($ext === 'pdf') {
            $this->rejectActivePdf($path);
        } elseif ($ext === 'docx' || $ext === 'xlsx') {
            $this->rejectActiveOffice($path, $ext);
        }
        $this->scan($path);

        return self::TYPES[$ext][0];
    }

    /**
     * @throws WorkflowException
     */
    private function rejectActivePdf(string $path): void
    {
        $data  = (string) file_get_contents($path);
        $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $data) ?? $data;
        if (preg_match('#/(JavaScript|JS|Launch|RichMedia|SubmitForm|ImportData|GoToR|GoToE)\b#', $names) === 1) {
            throw new WorkflowException('That PDF contains scripts or actions, which are not allowed. Please print it to a new PDF and attach that.');
        }
        if (preg_match('#/Encrypt\b#', $names) === 1) {
            throw new WorkflowException('That PDF is password-protected. Please remove the protection and attach it again.');
        }
    }

    /**
     * @throws WorkflowException
     */
    private function rejectActiveOffice(string $path, string $ext): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new WorkflowException('That file does not look like a real ' . strtoupper($ext) . ' file.');
        }

        $required = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        $found    = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = strtolower((string) $zip->getNameIndex($i));
            if ($name === $required) {
                $found = true;
            }
            if (str_contains($name, 'vbaproject') || str_contains($name, '/embeddings/') || str_contains($name, 'activex')) {
                $zip->close();

                throw new WorkflowException('That file contains macros or embedded objects, which are not allowed. Save a copy without them and attach that.');
            }
        }
        $zip->close();

        if (! $found) {
            throw new WorkflowException('That file does not look like a real ' . strtoupper($ext) . ' file.');
        }
    }

    /**
     * Same optional antivirus hook as the invoice PDF (InvoiceSettings.scanCommand), failing closed.
     *
     * @throws WorkflowException
     */
    private function scan(string $path): void
    {
        $template = trim((string) setting('InvoiceSettings.scanCommand'));
        if ($template === '') {
            return;
        }

        $cmd  = str_replace('{file}', escapeshellarg($path), $template);
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            log_message('error', 'Virus scan command could not be started.');

            throw new WorkflowException('The file could not be checked for viruses right now. Please try again later.');
        }
        $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            log_message('warning', 'Document refused by virus scan (exit ' . $code . '): ' . mb_substr(trim($out), 0, 300));

            throw new WorkflowException('The file did not pass the virus check and was not accepted.');
        }
    }

    /**
     * Validates and stores a document. Returns the new row id.
     *
     * @throws WorkflowException
     */
    public function store(int $invoiceId, int $userId, string $tmpPath, string $originalName, string $actedAs = 'normal', ?int $noteId = null): int
    {
        $mime = $this->validate($tmpPath, $originalName);

        // Measure before storing: an HTTP upload is moved by the file store.
        $size = (int) filesize($tmpPath);
        $hash = hash_file('sha256', $tmpPath);
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        try {
            service('fileStore')->put(self::KEY_PREFIX . $stored, $tmpPath);
        } catch (\RuntimeException $e) {
            throw new WorkflowException($e->getMessage());
        }

        $this->db->table('inv_documents')->insert([
            'invoice_id'    => $invoiceId,
            'original_name' => mb_substr(self::cleanName($originalName), 0, 255),
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

    public static function cleanName(string $name): string
    {
        return preg_replace('/[\x00-\x1F\x7F\\\\\/]+/', '', basename($name)) ?: 'document';
    }

    /**
     * @return list<array<string, mixed>> newest last
     */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->table('inv_documents d')
            ->select('d.*, u.display_name AS uploader_name, u.username AS uploader_username')
            ->join('inv_users u', 'u.id = d.uploaded_by', 'left')
            ->where('d.invoice_id', $invoiceId)->orderBy('d.id', 'ASC')->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $invoiceId, int $documentId): ?array
    {
        return $this->db->table('inv_documents')->where(['id' => $documentId, 'invoice_id' => $invoiceId])->get()->getRowArray();
    }

    /**
     * A readable local path, or null when purged or missing. With the S3 driver this is a temporary copy: read it, do not delete it.
     *
     * @param array<string, mixed> $doc
     */
    public function path(array $doc): ?string
    {
        if (! empty($doc['purged_at'])) {
            return null;
        }

        return service('fileStore')->localPath(self::key($doc));
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function key(array $doc): string
    {
        return self::KEY_PREFIX . basename((string) $doc['stored_name']);
    }
}
