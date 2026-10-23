<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Accountability report: everything a group-level user did on a step that was not theirs, and
 * who was given or lost group-level access. Administrators only (enforced by the controller).
 */
final class GroupLevelReport
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @param array{user?: int, company?: int, from?: string, to?: string} $filters
     *
     * @return list<array<string, mixed>> newest first
     */
    public function actions(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $rows = $this->query($filters)
            ->select('a.id, a.acted_at, a.action, a.step_order, a.acted_by, a.on_behalf_of,
                      u.display_name AS actor, o.display_name AS on_behalf_name,
                      i.id AS invoice_id, i.invoice_number, i.supplier, i.amount, i.currency, i.status, i.route_snapshot,
                      c.code AS company_code, n.body AS reason')
            ->orderBy('a.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        foreach ($rows as &$r) {
            $r['step_label'] = $this->stepLabel((string) $r['route_snapshot'], $r['step_order']);
            unset($r['route_snapshot']);
        }

        return $rows;
    }

    /**
     * @param array{user?: int, company?: int, from?: string, to?: string} $filters
     */
    public function count(array $filters = []): int
    {
        return $this->query($filters)->countAllResults();
    }

    /**
     * How many group-level actions each person took (same filters).
     *
     * @param array{user?: int, company?: int, from?: string, to?: string} $filters
     *
     * @return list<array{actor: string, n: int}>
     */
    public function byUser(array $filters = []): array
    {
        $rows = $this->query($filters)->select('u.display_name AS actor, COUNT(*) AS n', false)->groupBy('a.acted_by, u.display_name')->orderBy('n', 'DESC')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['actor' => (string) $r['actor'], 'n' => (int) $r['n']], $rows);
    }

    /**
     * Group-level access granted or removed, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function boundaryChanges(int $limit = 50): array
    {
        return $this->db->query(
            "SELECT l.created_at, l.event, admin.display_name AS by_name, target.display_name AS target_name
             FROM audit_log l
             LEFT JOIN users admin ON admin.id = l.user_id
             LEFT JOIN users target ON target.id = l.entity_id
             WHERE l.event IN ('group_level.granted', 'group_level.revoked')
             ORDER BY l.id DESC LIMIT " . (int) $limit,
        )->getResultArray();
    }

    /**
     * People who have taken a group-level action, for the filter list.
     *
     * @return list<array{id: int, name: string}>
     */
    public function actors(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT u.id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name
             FROM invoice_actions a JOIN users u ON u.id = a.acted_by
             WHERE a.acted_as = 'group_level' ORDER BY name",
        )->getResultArray();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * @param array{user?: int, company?: int, from?: string, to?: string} $filters
     */
    private function query(array $filters): \CodeIgniter\Database\BaseBuilder
    {
        $b = $this->db->table('invoice_actions a')
            ->join('invoices i', 'i.id = a.invoice_id')
            ->join('companies c', 'c.id = i.company_id')
            ->join('users u', 'u.id = a.acted_by')
            ->join('users o', 'o.id = a.on_behalf_of', 'left')
            ->join('invoice_notes n', 'n.id = a.note_id', 'left')
            ->where('a.acted_as', 'group_level');

        if (! empty($filters['user'])) {
            $b->where('a.acted_by', (int) $filters['user']);
        }
        if (! empty($filters['company'])) {
            $b->where('i.company_id', (int) $filters['company']);
        }
        if (! empty($filters['from']) && self::isDate($filters['from'])) {
            $b->where('a.acted_at >=', $filters['from'] . ' 00:00:00');
        }
        if (! empty($filters['to']) && self::isDate($filters['to'])) {
            $b->where('a.acted_at <=', $filters['to'] . ' 23:59:59');
        }

        return $b;
    }

    private function stepLabel(string $snapshot, mixed $order): string
    {
        if ($order === null || $snapshot === '') {
            return '';
        }
        $snap = json_decode($snapshot, true);
        foreach (is_array($snap) ? ($snap['steps'] ?? []) : [] as $s) {
            if ((int) $s['order'] === (int) $order) {
                $who = (string) ($s['label'] ?? '');

                return trim(($s['role'] ?? '') . ($who !== '' ? ' (' . $who . ')' : ''));
            }
        }

        return 'Step ' . (int) $order;
    }

    public static function isDate(string $v): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
