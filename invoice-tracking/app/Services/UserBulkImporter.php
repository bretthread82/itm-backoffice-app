<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Bulk user provisioning from CSV/XLSX.
 *
 * Columns: email, display_name, company_codes (";" separated), job_title, can_submit, can_approve,
 * group_level, is_admin, active. A blank cell means "leave unchanged" (or the default for a
 * new user). group_level and is_admin only apply when the actor is an administrator; managers can import but not change administrators.
 *
 * Flow: parse() -> validate() builds a preview and writes nothing. commit() re-validates and
 * refuses to write anything if the file has any error, so a bad file changes nothing.
 */
class UserBulkImporter
{
    public const MAX_ROWS = 2000;

    private const COLUMNS = ['email', 'display_name', 'company_codes', 'job_title', 'can_submit', 'can_approve', 'group_level', 'is_admin', 'active'];

    public function __construct(
        private ?BaseConnection $db = null,
        private ?AdminAccess $admin = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->db ??= db_connect();
        $this->audit ??= new AuditLogger($this->db);
        $this->admin ??= new AdminAccess($this->db, $this->audit);
    }

    /**
     * @return list<array<string, string>> rows keyed by column name, with a "_line" number
     */
    public function parse(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);

        if ($sheet === [] || count($sheet) < 2) {
            throw new RuntimeException('The file has no data rows.');
        }

        $header = array_map(
            static fn ($h): string => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))),
            array_shift($sheet),
        );

        if (! in_array('email', $header, true)) {
            throw new RuntimeException('The file needs an "email" column. Download the template for the expected layout.');
        }

        if (count($sheet) > self::MAX_ROWS) {
            throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
        }

        $rows = [];
        foreach ($sheet as $i => $cells) {
            $row = ['_line' => (string) ($i + 2)];
            foreach (self::COLUMNS as $col) {
                $idx       = array_search($col, $header, true);
                $row[$col] = $idx === false ? '' : trim((string) ($cells[$idx] ?? ''));
            }
            if (implode('', array_diff_key($row, ['_line' => 1])) === '') {
                continue; // blank line
            }
            $rows[] = $row;
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
            $companies[strtolower($c['code'])] = (int) $c['id'];
        }

        $out    = [];
        $seen   = [];
        $counts = ['create' => 0, 'update' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $errors = [];
            $email  = strtolower($row['email']);

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid or missing email.';
            } elseif (isset($seen[$email])) {
                $errors[] = 'Duplicate of line ' . $seen[$email] . ' in this file.';
            } else {
                $seen[$email] = $row['_line'];
            }

            $companyIds = [];
            foreach (array_filter(array_map('trim', explode(';', $row['company_codes']))) as $code) {
                $key = strtolower($code);
                if (isset($companies[$key])) {
                    $companyIds[] = $companies[$key];
                } else {
                    $errors[] = 'Unknown company code "' . $code . '".';
                }
            }

            $flags = [];
            foreach (['can_submit', 'can_approve', 'group_level', 'is_admin', 'active'] as $flag) {
                try {
                    $flags[$flag] = $this->parseBool($row[$flag]); // true|false|null (null = unchanged)
                } catch (\InvalidArgumentException) {
                    $errors[] = 'Column "' . $flag . '" must be Y/N (or blank).';
                }
            }

            $existing = $email !== '' && ! $errors ? $this->findUserByEmail($email) : null;
            $action   = $errors ? 'error' : ($existing ? 'update' : 'create');

            if ($action === 'create' && $row['display_name'] === '') {
                $errors[] = 'Display name is required for a new user.';
                $action   = 'error';
            }

            $counts[$action]++;
            $out[] = [
                'line'        => $row['_line'],
                'email'       => $email,
                'display_name' => $row['display_name'],
                'job_title'   => $row['job_title'],
                'company_ids' => array_values(array_unique($companyIds)),
                'flags'       => $flags,
                'action'      => $action,
                'user_id'     => $existing?->id,
                'errors'      => $errors,
            ];
        }

        return ['rows' => $out, 'counts' => $counts, 'ok' => $counts['error'] === 0 && $out !== []];
    }

    /**
     * Applies a validated file. Throws, and writes nothing, if the file has any error.
     *
     * @param list<array<string, string>> $rows
     *
     * @return array{created: int, updated: int, created_ids: list<int>}
     */
    public function commit(array $rows, int $actorId, bool $replaceMemberships = false): array
    {
        if (! $this->admin->canManage($actorId)) {
            throw new RuntimeException('Manager access is required.');
        }

        $preview = $this->validate($rows);
        if (! $preview['ok']) {
            throw new RuntimeException('The file has errors. Nothing was imported.');
        }

        $users   = model(UserModel::class);
        $created = [];
        $updated = 0;

        $this->db->transBegin();

        try {
            foreach ($preview['rows'] as $r) {
            if ($r['action'] === 'create') {
                $user = new User([
                    'email'        => $r['email'],
                    'password'     => bin2hex(random_bytes(24)), // unusable until the user sets one
                    'display_name' => $r['display_name'],
                    'active'       => ($r['flags']['active'] ?? true) ? 1 : 0,
                    'is_admin'     => 0,
                ]);
                $users->save($user);
                $userId = (int) $users->getInsertID();
                $users->addToDefaultGroup($users->findById($userId));
                $created[] = $userId;
            } else {
                $userId = (int) $r['user_id'];
                $this->admin->requireCanAdminister($actorId, $userId);
                $update = [];
                if ($r['display_name'] !== '') {
                    $update['display_name'] = $r['display_name'];
                }
                if (($r['flags']['active'] ?? null) !== null) {
                    $this->admin->setActive($actorId, $userId, (bool) $r['flags']['active']);
                }
                if ($update !== []) {
                    $this->db->table('users')->where('id', $userId)->update($update);
                }
                $updated++;
            }

            $this->applyMemberships($userId, $r, $replaceMemberships);

            if (($r['flags']['is_admin'] ?? null) !== null) {
                $this->admin->setAdmin($actorId, $userId, (bool) $r['flags']['is_admin']);
            }
            if (($r['flags']['group_level'] ?? null) !== null) {
                $r['flags']['group_level']
                    ? $this->admin->grantGroupLevel($actorId, $userId)
                    : $this->admin->revokeGroupLevel($actorId, $userId);
            }
            }

            if (! $this->db->transStatus()) {
                throw new RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw new RuntimeException('The import failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('users.bulk_import', $actorId, null, null, [
            'created' => count($created), 'updated' => $updated, 'replace_memberships' => $replaceMemberships,
        ]);

        return ['created' => count($created), 'updated' => $updated, 'created_ids' => $created];
    }

    /**
     * @param array<string, mixed> $r
     */
    private function applyMemberships(int $userId, array $r, bool $replace): void
    {
        $submit  = ($r['flags']['can_submit'] ?? null);
        $approve = ($r['flags']['can_approve'] ?? null);

        if ($replace) {
            $this->db->table('user_companies')->where('user_id', $userId)->delete();
        }

        foreach ($r['company_ids'] as $companyId) {
            $exists = $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->get()->getRowArray();

            if ($exists === null) {
                $this->db->table('user_companies')->insert([
                    'user_id'    => $userId,
                    'company_id' => $companyId,
                    'can_submit'  => $submit ? 1 : 0,
                    'can_approve' => $approve ? 1 : 0,
                    'job_title'  => $r['job_title'] !== '' ? $r['job_title'] : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $set = [];
                if ($submit !== null) {
                    $set['can_submit'] = $submit ? 1 : 0;
                }
                if ($approve !== null) {
                    $set['can_approve'] = $approve ? 1 : 0;
                }
                if ($r['job_title'] !== '') {
                    $set['job_title'] = $r['job_title'];
                }
                if ($set !== []) {
                    $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->update($set);
                }
            }
        }
    }

    private function findUserByEmail(string $email): ?User
    {
        $user = model(UserModel::class)->findByCredentials(['email' => $email]);

        return $user instanceof User ? $user : null;
    }

    /**
     * @return bool|null true/false, or null for a blank cell (leave unchanged)
     *
     * @throws \InvalidArgumentException for anything that is not Y/N/yes/no/1/0/true/false
     */
    private function parseBool(string $value): bool|null
    {
        $v = strtolower(trim($value));

        return match (true) {
            $v === ''                                   => null,
            in_array($v, ['y', 'yes', '1', 'true'], true) => true,
            in_array($v, ['n', 'no', '0', 'false'], true) => false,
            default                                     => throw new \InvalidArgumentException($value),
        };
    }
}
