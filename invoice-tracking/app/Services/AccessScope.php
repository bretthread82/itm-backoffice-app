<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ConnectionInterface;

/**
 * The only place that decides which companies (and so which invoices) a user can see.
 *
 * Visible companies = the user's own memberships, or every company when the user is in the
 * single Group Level Users boundary. Sub-groups are organisational only and never affect this.
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
        if ($this->isGroupLevel($userId)) {
            return null;
        }

        return $this->memberCompanyIds($userId);
    }

    /**
     * Companies the user is a direct member of (ignores the group-level boundary).
     *
     * @return list<int>
     */
    public function memberCompanyIds(int $userId): array
    {
        $rows = $this->db->table('user_companies')
            ->select('company_id')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['company_id'], $rows);
    }

    /**
     * Restrict a query to the invoices/companies the user may see.
     * A user with no companies sees nothing.
     */
    public function restrict(BaseBuilder $builder, int $userId, string $companyColumn = 'invoices.company_id'): BaseBuilder
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

    public function canViewInvoice(int $userId, int $invoiceId): bool
    {
        $row = $this->db->table('invoices')->select('company_id')->where('id', $invoiceId)->get()->getRowArray();

        return $row !== null && $this->canViewCompany($userId, (int) $row['company_id']);
    }
}
