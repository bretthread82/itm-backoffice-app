<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ConnectionInterface;
use Config\Backoffice;

/**
 * The only place that decides which companies (and so which company data) a user can see.
 *
 * Visible companies = the user's own memberships, or every company when the user is in the
 * group-level boundary. Company groups are organisational only and never affect access.
 * Every module query on company-owned data must go through restrict().
 */
class AccessScope
{
    public function __construct(private ?ConnectionInterface $db = null)
    {
        $this->db ??= db_connect();
    }

    public function isGroupLevel(int $userId): bool
    {
        return $this->db->table('group_level_users')->where('user_id', $userId)->countAllResults() > 0;
    }

    /**
     * @return list<int>|null null means all companies (group-level user)
     */
    public function companyIdsFor(int $userId): ?array
    {
        return $this->isGroupLevel($userId) ? null : $this->memberCompanyIds($userId);
    }

    /**
     * Companies the user is a direct member of (ignores the group-level boundary).
     *
     * @return list<int>
     */
    public function memberCompanyIds(int $userId): array
    {
        $rows = $this->db->table('user_companies')->select('company_id')->where('user_id', $userId)->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['company_id'], $rows);
    }

    /**
     * Restrict a query to rows of companies the user may see. A user with no companies sees nothing.
     */
    public function restrict(BaseBuilder $builder, int $userId, string $companyColumn): BaseBuilder
    {
        $ids = $this->companyIdsFor($userId);

        if ($ids === null) {
            return $builder;
        }

        if ($ids === []) {
            return $builder->where('1 = 0', null, false);
        }

        return $builder->whereIn($companyColumn, $ids);
    }

    public function canViewCompany(int $userId, int $companyId): bool
    {
        $ids = $this->companyIdsFor($userId);

        return $ids === null || in_array($companyId, $ids, true);
    }

    /**
     * Active companies the user may see, with their group name, ordered by name.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleCompanies(int $userId): array
    {
        $builder = $this->db->table('companies c')
            ->select('c.id, c.code, c.name, c.group_id, g.name AS group_name')
            ->join('company_groups g', 'g.id = c.group_id', 'left')
            ->where('c.active', 1)
            ->orderBy('c.name');

        $this->restrict($builder, $userId, 'c.id');

        return $builder->get()->getResultArray();
    }

    /**
     * Whether the user holds a module's per-company capability, e.g. 'invoices.approve', as set on
     * Manage company access. Group-level users gain nothing here; they need their own membership.
     * Undeclared capabilities are never held.
     */
    public function can(int $userId, int $companyId, string $capability): bool
    {
        $declared = config(Backoffice::class)->companyCapabilities()[$capability] ?? null;

        if ($declared === null) {
            return false;
        }

        if ($declared['column'] !== null) {
            return $this->flag($userId, $companyId, $declared['column']);
        }

        return $this->db->table('user_company_capabilities')
            ->where(['user_id' => $userId, 'company_id' => $companyId, 'capability' => $capability])
            ->countAllResults() > 0;
    }

    public function canSubmit(int $userId, int $companyId): bool
    {
        return $this->can($userId, $companyId, 'invoices.submit');
    }

    public function canApprove(int $userId, int $companyId): bool
    {
        return $this->can($userId, $companyId, 'invoices.approve');
    }

    private function flag(int $userId, int $companyId, string $column): bool
    {
        return $this->db->table('user_companies')
            ->where(['user_id' => $userId, 'company_id' => $companyId, $column => 1])
            ->countAllResults() > 0;
    }
}
