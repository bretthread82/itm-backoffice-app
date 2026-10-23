<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use CodeIgniter\Database\BaseConnection;

/**
 * Invoice PDFs. Validated by extension, MIME type and the %PDF magic bytes, stored under a random
 * name outside the web root and only ever served through a controller that checks access.
 */
final class InvoiceFiles
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public const KEY_PREFIX = 'invoices/';

    /** Folder used by the local driver (tests and the migration command read it directly). */
    public static function directory(): string
    {
        return WRITEPATH . 'uploads/invoices';
    }

    /**
     * @throws WorkflowException
     */
    public function validate(string $path, string $originalName, ?int $size = null): void
    {
        $size ??= (int) @filesize($path);
        $max    = max(1, (int) setting('InvoiceSettings.maxUploadMb')) * 1024 * 1024;

        if (! is_file($path) || $size <= 0) {
            throw new WorkflowException('The file is empty or could not be read.');
        }
        if ($size > $max) {
            throw new WorkflowException('The PDF is larger than ' . (int) setting('InvoiceSettings.maxUploadMb') . ' MB.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new WorkflowException('Only PDF files can be uploaded.');
        }

        $head = (string) file_get_contents($path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw new WorkflowException('That file is not a valid PDF.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== 'application/pdf') {
            throw new WorkflowException('That file is not a valid PDF.');
        }

        $this->rejectActiveContent($path);
        $this->scan($path);
    }

    /**
     * Invoices are plain documents. A PDF that can run scripts, launch programs or open links on its own,
     * or that is encrypted (so it cannot be inspected), is refused. Names can be written with #xx escapes
     * (/J#61vaScript), so those are decoded before looking.
     *
     * @throws WorkflowException
     */
    private function rejectActiveContent(string $path): void
    {
        $data = (string) file_get_contents($path);

        if (! str_contains(substr($data, -2048), '%%EOF')) {
            throw new WorkflowException('That PDF looks incomplete or damaged. Please export it again.');
        }

        $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $data) ?? $data;
        if (preg_match('#/(JavaScript|JS|Launch|RichMedia|SubmitForm|ImportData|GoToR|GoToE)\b#', $names) === 1) {
            throw new WorkflowException('That PDF contains scripts or actions, which are not allowed. Please print it to a new PDF and upload that.');
        }
        if (preg_match('#/Encrypt\b#', $names) === 1) {
            throw new WorkflowException('That PDF is password-protected. Please remove the protection and upload it again.');
        }
    }

    /**
     * Optional antivirus hook. Set InvoiceSettings.scanCommand (for example in .env) to a command containing {file},
     * e.g. "clamdscan --no-summary {file}". Exit code 0 = clean, anything else = refused. If it cannot be run at
     * all the upload is refused too (fail closed), so a broken scanner is noticed rather than silently skipped.
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
            log_message('warning', 'Upload refused by virus scan (exit ' . $code . '): ' . mb_substr(trim($out), 0, 300));

            throw new WorkflowException('The file did not pass the virus check and was not accepted.');
        }
    }

    /**
     * Stores a validated PDF as the invoice's current file. Earlier files stay on disk (marked not
     * current) until the retention job removes them.
     *
     * @throws WorkflowException
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
            throw new WorkflowException($e->getMessage());
        }

        $clean = preg_replace('/[\x00-\x1F\x7F\\\\\/]+/', '', basename($originalName)) ?: 'invoice.pdf';

        $this->db->table('inv_files')->where('invoice_id', $invoiceId)->update(['is_current' => 0]);
        $this->db->table('inv_files')->insert([
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
        return $this->db->table('inv_files')->where(['invoice_id' => $invoiceId, 'is_current' => 1])->orderBy('id', 'DESC')->get()->getRowArray();
    }

    /**
     * A readable local path for a stored file, or null when it has been purged or is missing. With the S3 driver
     * this is a temporary copy: read it, do not delete or keep it.
     *
     * @param array<string, mixed> $file
     */
    public function path(array $file): ?string
    {
        if (! empty($file['purged_at'])) {
            return null;
        }

        return service('fileStore')->localPath(self::KEY_PREFIX . basename((string) $file['stored_name']));
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function key(array $file): string
    {
        return self::KEY_PREFIX . basename((string) $file['stored_name']);
    }
}
