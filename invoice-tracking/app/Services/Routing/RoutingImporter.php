<?php

declare(strict_types=1);

namespace App\Services\Routing;

use App\Services\AdminAccess;
use App\Services\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads a routing export, shows what would happen (analyse), and writes it (commit).
 * analyse() never writes. commit() is one transaction and requires an administrator.
 */
final class RoutingImporter
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private ?BaseConnection $db = null,
        private ?RoutingNormalizer $normalizer = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->db ??= db_connect();
        $this->normalizer ??= new RoutingNormalizer();
        $this->audit ??= new AuditLogger($this->db);
    }

    /**
     * @return list<list<mixed>>
     */
    public function readSheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);

        if (count($sheet) < 2) {
            throw new RuntimeException('The file has no data rows.');
        }
        if (count($sheet) > self::MAX_ROWS) {
            throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows.');
        }

        return $sheet;
    }

    /**
     * @return array<string, mixed>
     */
    public function analyse(string $path): array
    {
        $result = $this->normalizer->normalise($this->readSheet($path));
        $people = new PeopleResolver($this->db);

        $existing = [];
        foreach ($this->db->query(
            'SELECT c.code, c.name AS company_name, t.name AS type_name, r.variant
             FROM routes r JOIN companies c ON c.id = r.company_id JOIN invoice_types t ON t.id = r.invoice_type_id',
        )->getResultArray() as $e) {
            $existing[RoutingNormalizer::companyCode($e['code']) . '|' . strtolower($e['type_name']) . '|' . $e['variant']] = true;
        }

        $companies = array_map('strtoupper', array_column($this->db->table('companies')->select('code')->get()->getResultArray(), 'code'));

        $peopleSummary = [];
        $shared        = [];
        foreach ($result['routes'] as $key => &$route) {
            $route['exists']         = isset($existing[$key]);
            $route['company_exists'] = in_array($route['company_code'], $companies, true);

            foreach ($route['steps'] as $s) {
                $pk = RoutingNormalizer::personKey($s['person']);
                if ($s['assignee_kind'] === 'user') {
                    $peopleSummary[$pk] ??= ['name' => $s['person'], 'email' => '', 'routes' => 0] + $people->classify($s['person'], $s['email']);
                    if ($s['email'] !== '' && $peopleSummary[$pk]['email'] === '') {
                        $peopleSummary[$pk] = ['name' => $s['person'], 'email' => $s['email'], 'routes' => $peopleSummary[$pk]['routes']] + $people->classify($s['person'], $s['email']);
                    }
                    $peopleSummary[$pk]['routes']++;
                } else {
                    $shared[$pk] ??= ['name' => $s['person'], 'kind' => $s['assignee_kind'], 'routes' => 0];
                    $shared[$pk]['routes']++;
                }
            }
        }
        unset($route);

        $byStatus = [];
        foreach ($peopleSummary as $p) {
            $byStatus[$p['status']] = ($byStatus[$p['status']] ?? 0) + 1;
        }

        ksort($peopleSummary);
        ksort($shared);

        $result['people']        = array_values($peopleSummary);
        $result['people_counts'] = $byStatus;
        $result['shared']        = array_values($shared);

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function commit(string $path, int $actorId, bool $overwriteExisting = false): array
    {
        if (! (new AdminAccess($this->db, $this->audit))->canManage($actorId)) {
            throw new RuntimeException('Manager access is required.');
        }

        $analysis = $this->analyse($path);
        $routes   = array_filter($analysis['routes'], static fn (array $r): bool => ! $r['blocked']);
        if ($routes === []) {
            throw new RuntimeException('There is nothing importable in this file.');
        }

        $people  = new PeopleResolver($this->db);
        $summary = array_fill_keys([
            'companies_created', 'types_created', 'routes_created', 'routes_updated', 'routes_skipped_existing', 'routes_blocked',
            'steps', 'users_created', 'placeholders_created', 'emails_filled', 'unassigned',
        ], 0);
        $summary['routes_blocked'] = $analysis['stats']['blocked'];

        $this->db->transBegin();

        try {
            foreach ($routes as $route) {
                $companyId = $this->company($route, $summary);
                $typeId    = $this->invoiceType($companyId, $route, $summary);

                $existing = $this->db->table('routes')
                    ->where(['company_id' => $companyId, 'invoice_type_id' => $typeId, 'variant' => $route['variant']])
                    ->get()->getRowArray();

                if ($existing !== null && ! $overwriteExisting) {
                    $summary['routes_skipped_existing']++;
                    continue;
                }

                if ($existing === null) {
                    $this->db->table('routes')->insert([
                        'company_id'      => $companyId,
                        'invoice_type_id' => $typeId,
                        'variant'         => $route['variant'],
                        'variant_trigger' => 'none',
                        'version'         => 1,
                        'active'          => $route['active'] ? 1 : 0,
                        'source_title'    => $route['source_title'],
                        'created_at'      => date('Y-m-d H:i:s'),
                    ]);
                    $routeId = (int) $this->db->insertID();
                    $summary['routes_created']++;
                } else {
                    // Keep the admin's activation and trigger choices; only the steps are replaced.
                    $routeId = (int) $existing['id'];
                    $this->db->table('route_steps')->where('route_id', $routeId)->delete();
                    $this->db->table('routes')->where('id', $routeId)->update(['updated_at' => date('Y-m-d H:i:s')]);
                    $summary['routes_updated']++;
                }

                foreach ($route['steps'] as $step) {
                    $this->step($routeId, $step, $people, $summary);
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

        $this->audit->log('routing.imported', $actorId, null, null, $summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, int>   $summary
     */
    private function company(array $route, array &$summary): int
    {
        $row = $this->db->table('companies')->where('code', $route['company_code'])->get()->getRowArray()
            ?? $this->db->table('companies')->where('LOWER(name)', strtolower($route['company_name']))->get()->getRowArray();

        if ($row !== null) {
            return (int) $row['id'];
        }

        $this->db->table('companies')->insert([
            'code'       => $route['company_code'],
            'name'       => $route['company_name'],
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $summary['companies_created']++;

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, int>   $summary
     */
    private function invoiceType(int $companyId, array $route, array &$summary): int
    {
        $row = $this->db->table('invoice_types')->where('company_id', $companyId)->where('LOWER(name)', strtolower($route['type_name']))->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }

        $this->db->table('invoice_types')->insert([
            'company_id' => $companyId,
            'name'       => $route['type_name'],
            'active'     => $route['archived'] ? 0 : 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $summary['types_created']++;

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, int>   $summary
     */
    private function step(int $routeId, array $step, PeopleResolver $people, array &$summary): void
    {
        $userId = null;
        $teamId = null;
        $email  = null;

        if ($step['assignee_kind'] === 'user') {
            $resolved = $people->resolve($step['person'], $step['email']);
            $userId   = $resolved['user_id'];

            match ($resolved['status']) {
                PeopleResolver::NEW_WITH_EMAIL  => $summary['users_created']++,
                PeopleResolver::NEW_PLACEHOLDER => $summary['placeholders_created']++,
                PeopleResolver::FILL_EMAIL      => $summary['emails_filled']++,
                PeopleResolver::AMBIGUOUS       => $summary['unassigned']++,
                default                         => null,
            };
        } elseif ($step['assignee_kind'] === 'team') {
            $teamId = $this->team($step['person'], $step['email']);
        } elseif ($step['email'] !== '') {
            $email = $step['email'];
        }

        $this->db->table('route_steps')->insert([
            'route_id'         => $routeId,
            'step_order'       => $step['order'],
            'step_type'        => $step['step_type'],
            'job_title_id'     => $this->jobTitle($step['role']),
            'assignee_kind'    => $step['assignee_kind'],
            'assignee_user_id' => $userId,
            'team_id'          => $teamId,
            'assignee_email'   => $email,
            'assignee_label'   => $step['person'],
        ]);
        $summary['steps']++;
    }

    /**
     * Finds the team by name or creates it (no members yet; an administrator adds them). The
     * sheet's email, if any, becomes the team's group mailbox when it has none.
     */
    private function team(string $name, string $email): int
    {
        $row = $this->db->table('teams')->where('LOWER(name)', mb_strtolower($name))->get()->getRowArray();
        if ($row !== null) {
            if ($email !== '' && trim((string) $row['group_email']) === '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->db->table('teams')->where('id', (int) $row['id'])->update(['group_email' => strtolower($email)]);
            }

            return (int) $row['id'];
        }

        $this->db->table('teams')->insert([
            'name'        => $name,
            'group_email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null,
            'active'      => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function jobTitle(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $row = $this->db->table('job_titles')->where('LOWER(name)', strtolower($name))->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }

        $this->db->table('job_titles')->insert(['name' => $name]);

        return (int) $this->db->insertID();
    }
}
