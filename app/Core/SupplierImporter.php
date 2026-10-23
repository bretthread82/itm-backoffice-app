<?php

declare(strict_types=1);

namespace App\Core;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * CSV bulk upload of suppliers. Columns: code (required, unique), name (required, unique), active
 * (optional Y/N). Suppliers are matched by code, so an updated list can be uploaded again at any time.
 * A file with any error writes nothing.
 */
class SupplierImporter
{
    public const MAX_ROWS = 5000;

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
                $first    = (string) ($cells[0] ?? '');
                $cells[0] = str_starts_with($first, "\xEF\xBB\xBF") ? substr($first, 3) : $first;
                $header   = array_map(static fn ($h): string => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), $cells);
                // Accept the column names as people tend to write them.
                $header = array_map(static fn (string $h): string => match ($h) {
                    'supplier_code' => 'code', 'supplier_name' => 'name', default => $h,
                }, $header);
                if (! in_array('code', $header, true) || ! in_array('name', $header, true)) {
                    fclose($fh);

                    throw new RuntimeException('The file needs "code" and "name" columns. Download the template for the expected layout.');
                }

                continue;
            }

            $row = ['_line' => (string) $line];
            foreach (['code', 'name', 'active'] as $col) {
                $idx       = array_search($col, $header, true);
                $row[$col] = $idx === false ? '' : trim((string) ($cells[$idx] ?? ''));
            }
            if ($row['code'] . $row['name'] . $row['active'] === '') {
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
        $byCode = [];
        $byName = [];
        foreach ($this->db->table('suppliers')->select('id, code, name')->get()->getResultArray() as $s) {
            $byCode[mb_strtolower((string) $s['code'])] = (int) $s['id'];
            $byName[mb_strtolower((string) $s['name'])] = (int) $s['id'];
        }

        $seenCode = [];
        $seenName = [];
        $out      = [];
        $counts   = ['create' => 0, 'update' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $errors = [];
            $ck     = mb_strtolower($r['code']);
            $nk     = mb_strtolower($r['name']);

            if ($r['code'] === '') {
                $errors[] = 'Code is required.';
            } elseif (mb_strlen($r['code']) > 50) {
                $errors[] = 'Code is longer than 50 characters.';
            } elseif (isset($seenCode[$ck])) {
                $errors[] = 'Code repeats line ' . $seenCode[$ck] . '.';
            } else {
                $seenCode[$ck] = $r['_line'];
            }

            if ($r['name'] === '') {
                $errors[] = 'Name is required.';
            } elseif (mb_strlen($r['name']) > 200) {
                $errors[] = 'Name is longer than 200 characters.';
            } elseif (isset($seenName[$nk])) {
                $errors[] = 'Name repeats line ' . $seenName[$nk] . '.';
            } else {
                $seenName[$nk] = $r['_line'];
                // A name already used by a different supplier (another code) would clash.
                if (isset($byName[$nk]) && $byName[$nk] !== ($byCode[$ck] ?? null)) {
                    $errors[] = 'Another supplier already has this name.';
                }
            }

            $flag   = strtolower($r['active']);
            $active = null;
            if (in_array($flag, ['y', 'yes', '1', 'true'], true)) {
                $active = true;
            } elseif (in_array($flag, ['n', 'no', '0', 'false'], true)) {
                $active = false;
            } elseif ($flag !== '') {
                $errors[] = 'Active must be Y or N.';
            }

            $action = $errors !== [] ? 'error' : (isset($byCode[$ck]) ? 'update' : 'create');
            $counts[$action]++;
            $out[] = ['line' => $r['_line'], 'code' => $r['code'], 'name' => $r['name'], 'active' => $active, 'action' => $action, 'errors' => $errors];
        }

        return ['rows' => $out, 'counts' => $counts, 'ok' => $counts['error'] === 0];
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return array{created: int, updated: int}
     */
    public function commit(array $rows, int $actorId): array
    {
        $preview = $this->validate($rows);
        if (! $preview['ok']) {
            throw new RuntimeException('The file has errors and was not imported.');
        }

        $result = ['created' => 0, 'updated' => 0];
        $now    = date('Y-m-d H:i:s');

        $this->db->transStart();
        foreach ($preview['rows'] as $r) {
            $found = $this->db->table('suppliers')->where('LOWER(code)', mb_strtolower($r['code']))->get()->getRowArray();
            if ($found === null) {
                $this->db->table('suppliers')->insert([
                    'code' => $r['code'], 'name' => $r['name'], 'active' => $r['active'] === false ? 0 : 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $result['created']++;
            } else {
                $set = ['name' => $r['name'], 'updated_at' => $now];
                if ($r['active'] !== null) {
                    $set['active'] = $r['active'] ? 1 : 0;
                }
                $this->db->table('suppliers')->where('id', $found['id'])->update($set);
                $result['updated']++;
            }
        }
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new RuntimeException('The import failed and nothing was saved.');
        }

        service('audit')->log('supplier.bulk_uploaded', 'supplier', null, null, $result, $actorId);

        return $result;
    }
}
