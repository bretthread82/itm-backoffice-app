<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * CSV bulk upload of default invoice types. Columns: name (required), company_codes (optional,
 * semicolon separated; those companies get a copy), active (optional Y/N, default Y).
 * A file with any error writes nothing.
 */
class DefaultTypeImporter
{
    public const MAX_ROWS = 1000;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return list<array<string, string>>
     */
    public function parse(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('The file could not be opened.');
        }

        $rows   = [];
        $header = null;
        $line   = 0;
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($header === null) {
                $first = (string) ($cells[0] ?? '');
                $cells[0] = str_starts_with($first, "\xEF\xBB\xBF") ? substr($first, 3) : $first;
                $header = array_map(static fn ($h): string => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), $cells);
                if (! in_array('name', $header, true)) {
                    fclose($fh);

                    throw new RuntimeException('The file needs a "name" column. Download the template for the expected layout.');
                }

                continue;
            }

            $row = ['_line' => (string) $line];
            foreach (['name', 'company_codes', 'active'] as $col) {
                $idx       = array_search($col, $header, true);
                $row[$col] = $idx === false ? '' : trim((string) ($cells[$idx] ?? ''));
            }
            if ($row['name'] . $row['company_codes'] . $row['active'] === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fclose($fh);

                throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
            }
        }
        fclose($fh);

        if ($rows === []) {
            throw new RuntimeException('The file has no data rows.');
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, ok: bool}
     */
    public function validate(array $rows): array
    {
        $companies = [];
        foreach ($this->db->table('companies')->select('id, code')->get()->getResultArray() as $c) {
            $companies[strtolower((string) $c['code'])] = (int) $c['id'];
        }
        $existing = [];
        foreach ($this->db->table('default_invoice_types')->select('id, name')->get()->getResultArray() as $t) {
            $existing[mb_strtolower((string) $t['name'])] = (int) $t['id'];
        }

        $seen   = [];
        $out    = [];
        $counts = ['create' => 0, 'update' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $errors = [];
            $key    = mb_strtolower($r['name']);
            if ($r['name'] === '') {
                $errors[] = 'Name is required.';
            } elseif (mb_strlen($r['name']) > 200) {
                $errors[] = 'Name is longer than 200 characters.';
            } elseif (isset($seen[$key])) {
                $errors[] = 'Duplicate of line ' . $seen[$key] . '.';
            } else {
                $seen[$key] = $r['_line'];
            }

            $active = null;
            $flag   = strtolower($r['active']);
            if ($flag === '') {
                $active = null;
            } elseif (in_array($flag, ['y', 'yes', '1', 'true'], true)) {
                $active = true;
            } elseif (in_array($flag, ['n', 'no', '0', 'false'], true)) {
                $active = false;
            } else {
                $errors[] = 'Active must be Y or N.';
            }

            $companyIds = [];
            foreach (array_filter(array_map('trim', explode(';', $r['company_codes'])), static fn ($c): bool => $c !== '') as $code) {
                if (isset($companies[strtolower($code)])) {
                    $companyIds[] = $companies[strtolower($code)];
                } else {
                    $errors[] = 'Unknown company code "' . $code . '".';
                }
            }
            if ($companyIds !== [] && $active === false) {
                $errors[] = 'An inactive type cannot be assigned to companies.';
            }

            $action = $errors !== [] ? 'error' : (isset($existing[$key]) ? 'update' : 'create');
            $counts[$action]++;
            $out[] = [
                'line' => $r['_line'], 'name' => $r['name'], 'action' => $action, 'errors' => $errors,
                'active' => $active, 'company_ids' => array_values(array_unique($companyIds)),
            ];
        }

        return ['rows' => $out, 'counts' => $counts, 'ok' => $counts['error'] === 0];
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return array{created: int, updated: int, copies_added: int, copies_existing: int}
     */
    public function commit(array $rows, int $actorId): array
    {
        $preview = $this->validate($rows);
        if (! $preview['ok']) {
            throw new RuntimeException('The file has errors and was not imported.');
        }

        $assigner = new DefaultTypeAssigner($this->db);
        $result   = ['created' => 0, 'updated' => 0, 'copies_added' => 0, 'copies_existing' => 0];
        $now      = date('Y-m-d H:i:s');

        $this->db->transStart();
        foreach ($preview['rows'] as $r) {
            $found = $this->db->table('default_invoice_types')->where('LOWER(name)', mb_strtolower($r['name']))->get()->getRowArray();
            if ($found === null) {
                $this->db->table('default_invoice_types')->insert([
                    'name' => $r['name'], 'active' => $r['active'] === false ? 0 : 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $id = (int) $this->db->insertID();
                $result['created']++;
                $name = $r['name'];
            } else {
                $id   = (int) $found['id'];
                $name = (string) $found['name'];
                if ($r['active'] !== null) {
                    $this->db->table('default_invoice_types')->where('id', $id)->update(['active' => $r['active'] ? 1 : 0, 'updated_at' => $now]);
                }
                $result['updated']++;
            }

            // Assigning needs the type to be active, whatever the file said for an existing one.
            $isActive = (int) ($this->db->table('default_invoice_types')->select('active')->where('id', $id)->get()->getRow('active') ?? 0) === 1;
            if (! $isActive) {
                continue;
            }
            foreach ($r['company_ids'] as $companyId) {
                $assigner->assign($id, $name, $companyId) === 'created' ? $result['copies_added']++ : $result['copies_existing']++;
            }
        }
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new RuntimeException('The import failed and nothing was saved.');
        }

        (new AuditLogger())->log('default_invoice_type.bulk_uploaded', $actorId, 'default_invoice_type', null, $result);

        return $result;
    }
}
