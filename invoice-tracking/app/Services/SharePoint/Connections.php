<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

use App\Services\AdminAccess;
use App\Services\AuditLogger;
use App\Services\WorkflowException;
use CodeIgniter\Database\BaseConnection;

/**
 * SharePoint connection records: which one a company uses, validation, and saving.
 *
 * Rules:
 *  - A company assigned its own connection uses only that one (never silently falls back to the default).
 *    A company with none uses the default connection.
 *  - A connection that is disabled or incomplete is never used, so no SharePoint call is ever made for it.
 *  - A connection can only be switched on after a successful test of its current settings. Changing any
 *    setting clears the test result, so an untested change can never go live.
 *  - Client secrets are stored encrypted and are never shown. Leaving the field blank keeps the saved one.
 */
final class Connections
{
    /** Invoice values a SharePoint column can be filled from. */
    public const MAPPING_KEYS = [
        'invoice_id'       => 'Invoice ID in this app',
        'invoice_number'   => 'Invoice number',
        'supplier'         => 'Supplier',
        'amount'           => 'Amount (number)',
        'currency'         => 'Currency',
        'invoice_date'     => 'Invoice date',
        'due_date'         => 'Due date',
        'po_reference'     => 'PO / reference',
        'company_code'     => 'Company code',
        'company_name'     => 'Company name',
        'invoice_type'     => 'Invoice type',
        'status'           => 'Status',
        'submitted_by'     => 'Uploaded by',
        'submitted_at'     => 'Submitted at',
        'completed_at'     => 'Completed at',
        'approval_summary' => 'Who approved, and when (text)',
        'file_url'         => 'Link to the PDF in SharePoint',
        'app_url'          => 'Link to the invoice in this app',
    ];

    /** Used when a connection has no mapping yet. Only the one column every list has. */
    public const DEFAULT_MAPPING = ['Title' => 'invoice_number'];

    /** Settings that, when changed, require the connection to be tested again. */
    private const TESTED_FIELDS = ['tenant_id', 'client_id', 'site_url', 'list_name', 'library_name', 'folder', 'field_mapping'];

    public function __construct(private ?BaseConnection $db = null, private ?AuditLogger $audit = null)
    {
        $this->db    ??= db_connect();
        $this->audit ??= new AuditLogger($this->db);
    }

    // ------------------------------------------------------------------ which connection

    /**
     * The usable (enabled and complete) connection for a company, or null.
     *
     * @return array<string, mixed>|null
     */
    public function forCompany(int $companyId): ?array
    {
        $company = $this->db->table('companies')->select('sharepoint_connection_id')->where('id', $companyId)->get()->getRowArray();
        $own     = (int) ($company['sharepoint_connection_id'] ?? 0);

        $row = $own > 0
            ? $this->find($own)
            : $this->db->table('sharepoint_connections')->where('is_default', 1)->get()->getRowArray();

        return $row !== null && (int) $row['enabled'] === 1 && $this->missing($row) === [] ? $row : null;
    }

    public function idFor(int $companyId): ?int
    {
        $row = $this->forCompany($companyId);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->table('sharepoint_connections')->where('id', $id)->get()->getRowArray();
    }

    /**
     * Everything the Graph client needs, with the secret decrypted. Never log or display this.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function forClient(array $row): array
    {
        $mapping = json_decode((string) ($row['field_mapping'] ?? ''), true);

        return [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'tenant'    => (string) $row['tenant_id'],
            'client_id' => (string) $row['client_id'],
            'secret'    => SecretBox::open($row['client_secret'] ?? null),
            'site_url'  => (string) $row['site_url'],
            'library'   => trim((string) ($row['library_name'] ?? '')) ?: 'Documents',
            'list'      => trim((string) ($row['list_name'] ?? '')),
            'folder'    => trim((string) ($row['folder'] ?? '')) ?: 'Invoices',
            'mapping'   => is_array($mapping) && $mapping !== [] ? $mapping : self::DEFAULT_MAPPING,
        ];
    }

    /**
     * What is still missing before the connection could be used.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public function missing(array $row): array
    {
        $m = [];
        foreach (['tenant_id' => 'tenant ID', 'client_id' => 'client ID', 'client_secret' => 'client secret', 'site_url' => 'site URL'] as $k => $label) {
            if (trim((string) ($row[$k] ?? '')) === '') {
                $m[] = $label;
            }
        }

        return $m;
    }

    // ------------------------------------------------------------------ mapping

    /**
     * @return array{mapping: array<string, string>|null, error: string|null} empty text means "use the default"
     */
    public static function parseMapping(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['mapping' => [], 'error' => null];
        }

        $data = json_decode($text, true);
        if (! is_array($data) || array_is_list($data)) {
            return ['mapping' => null, 'error' => 'The column mapping must be a JSON object such as {"Title": "invoice_number", "Supplier": "supplier"}.'];
        }

        foreach ($data as $column => $key) {
            if (! is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $column) !== 1) {
                return ['mapping' => null, 'error' => 'Column "' . (string) $column . '" is not a valid SharePoint internal column name (letters, digits and underscores only).'];
            }
            if (! is_string($key) || ! isset(self::MAPPING_KEYS[$key])) {
                return ['mapping' => null, 'error' => 'Column "' . $column . '" is mapped to "' . (is_string($key) ? $key : '?') . '", which is not an invoice value. Allowed: ' . implode(', ', array_keys(self::MAPPING_KEYS)) . '.'];
            }
        }

        return ['mapping' => $data, 'error' => null];
    }

    // ------------------------------------------------------------------ save

    /**
     * @param array<string, mixed> $in form values
     *
     * @return array{id: int|null, errors: list<string>}
     */
    public function save(array $in, ?int $id, int $actorId): array
    {
        if (! (new AdminAccess($this->db, $this->audit))->isAdmin($actorId)) {
            throw new WorkflowException('Administrator access is required.');
        }

        $existing = $id !== null ? $this->find($id) : null;
        if ($id !== null && $existing === null) {
            throw new WorkflowException('Connection not found.');
        }

        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

        $new = [
            'name'          => $text('name', 150),
            'tenant_id'     => $text('tenant_id', 100),
            'client_id'     => $text('client_id', 100),
            'site_url'      => rtrim($text('site_url', 500), '/'),
            'list_name'     => $text('list_name', 200),
            'library_name'  => $text('library_name', 200),
            'folder'        => trim($text('folder', 200), '/ ') ?: 'Invoices',
            'field_mapping' => trim((string) ($in['field_mapping'] ?? '')),
        ];
        $secret   = (string) ($in['client_secret'] ?? '');
        $wantOn   = ! empty($in['enabled']);
        $wantDef  = ! empty($in['is_default']);

        $errors = [];
        if ($new['name'] === '') {
            $errors[] = 'Give the connection a name.';
        }
        if ($new['tenant_id'] !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/', $new['tenant_id']) !== 1) {
            $errors[] = 'The tenant ID is not valid.';
        }
        if ($new['site_url'] !== '' && ! $this->validSiteUrl($new['site_url'])) {
            $errors[] = 'The site URL must be an https address such as https://contoso.sharepoint.com/sites/InvoiceApprovalandTracking.';
        }
        if (preg_match('#[\\\\:*?"<>|]#', $new['folder']) === 1) {
            $errors[] = 'The folder name cannot contain \\ : * ? " < > |.';
        }
        $parsed = self::parseMapping($new['field_mapping']);
        if ($parsed['error'] !== null) {
            $errors[] = $parsed['error'];
        }

        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        // Has anything that needs re-testing changed?
        $changed = $existing === null || $secret !== '';
        foreach (self::TESTED_FIELDS as $f) {
            if ($existing !== null && trim((string) ($existing[$f] ?? '')) !== trim((string) ($new[$f] ?? ''))) {
                $changed = true;
            }
        }

        $sealed = $secret !== '' ? SecretBox::seal($secret) : ($existing['client_secret'] ?? null);
        $row    = $new + ['client_secret' => $sealed];

        if ($wantOn) {
            $missing = $this->missing($row);
            if ($missing !== []) {
                $errors[] = 'It cannot be switched on yet: missing ' . implode(', ', $missing) . '.';
            } elseif ($changed || $existing === null || empty($existing['last_tested_at']) || ! empty($existing['last_error'])) {
                $errors[] = 'It cannot be switched on until its current settings have been tested successfully. Save, press "Test connection", then switch it on.';
            }
        }
        if ($errors !== []) {
            // Save the edits but leave it off, so the admin does not lose their work.
            $wantOn = false;
        }

        $now  = date('Y-m-d H:i:s');
        $data = $row + [
            'enabled'    => $wantOn ? 1 : 0,
            'is_default' => $wantDef ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($changed) {
            $data['last_tested_at'] = null;
            $data['last_error']     = null;
        }

        $this->db->transBegin();
        try {
            if ($wantDef) {
                $this->db->table('sharepoint_connections')->update(['is_default' => 0]);
            }
            if ($existing === null) {
                $this->db->table('sharepoint_connections')->insert($data + ['created_at' => $now]);
                $id = (int) $this->db->insertID();
            } else {
                $this->db->table('sharepoint_connections')->where('id', $id)->update($data);
            }
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('Database error.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw new WorkflowException('The connection could not be saved: ' . $e->getMessage(), 0, $e);
        }

        $wasOn = $existing !== null && (int) $existing['enabled'] === 1;
        $this->audit->log($existing === null ? 'sharepoint.created' : 'sharepoint.updated', $actorId, 'sharepoint_connection', $id, [
            'secret_changed' => $secret !== '', 'enabled' => $wantOn, 'default' => $wantDef,
        ]);
        if ($wantOn !== $wasOn) {
            $this->audit->log($wantOn ? 'sharepoint.enabled' : 'sharepoint.disabled', $actorId, 'sharepoint_connection', $id);
        }

        return ['id' => $id, 'errors' => $errors];
    }

    public function recordTest(int $id, ?string $error): void
    {
        $this->db->table('sharepoint_connections')->where('id', $id)->update([
            'last_tested_at' => date('Y-m-d H:i:s'),
            'last_error'     => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);

        // A failing connection must not stay live.
        if ($error !== null) {
            $this->db->table('sharepoint_connections')->where('id', $id)->update(['enabled' => 0]);
        }
    }

    /**
     * @throws WorkflowException when companies still use it
     */
    public function delete(int $id, int $actorId): void
    {
        if (! (new AdminAccess($this->db, $this->audit))->isAdmin($actorId)) {
            throw new WorkflowException('Administrator access is required.');
        }
        $uses = $this->db->table('companies')->where('sharepoint_connection_id', $id)->countAllResults();
        if ($uses > 0) {
            throw new WorkflowException('Companies still use this connection (' . $uses . '). Assign them another one first.');
        }

        $this->db->table('sharepoint_connections')->where('id', $id)->delete();
        $this->audit->log('sharepoint.deleted', $actorId, 'sharepoint_connection', $id);
    }

    private function validSiteUrl(string $url): bool
    {
        $p = parse_url($url);

        return $p !== false && ($p['scheme'] ?? '') === 'https' && ! empty($p['host'])
            && preg_match('/^[A-Za-z0-9.-]+$/', (string) $p['host']) === 1 && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['query']) && ! isset($p['fragment']) && ! isset($p['port']);
    }
}
