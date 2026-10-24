<?php

declare(strict_types=1);

namespace App\Core\Payables;

use App\Core\Tenancy\AccessScope;

use CodeIgniter\Database\BaseConnection;

/**
 * Approval delegation: A hands their approvals to B for a period (optionally one company).
 * One hop only: a delegate cannot pass a delegation on. A delegation never widens what the delegate
 * can see: it only works in companies the delegate can already view (member or group-level).
 */
final class DelegationResolver
{
    public function __construct(private ?BaseConnection $db = null, private ?AccessScope $scope = null, private ?InvoiceAudit $audit = null)
    {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
        $this->audit ??= new InvoiceAudit($this->db);
    }

    /**
     * Active delegates of $delegatorId for this company right now.
     *
     * @return list<int>
     */
    public function delegatesOf(int $delegatorId, int $companyId, ?string $at = null): array
    {
        $at   ??= date('Y-m-d H:i:s');
        $rows = $this->db->table('inv_delegations d')
            ->select('d.delegate_id')
            ->join('inv_users u', 'u.id = d.delegate_id AND u.active = 1')
            ->where('d.delegator_id', $delegatorId)->where('d.active', 1)
            ->where('d.starts_at <=', $at)->where('d.ends_at >=', $at)
            ->groupStart()->where('d.company_id', null)->orWhere('d.company_id', $companyId)->groupEnd()
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['delegate_id'];
            if ($this->scope->canViewCompany($id, $companyId)) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    public function isDelegate(int $delegatorId, int $userId, int $companyId, ?string $at = null): bool
    {
        return in_array($userId, $this->delegatesOf($delegatorId, $companyId, $at), true);
    }

    /**
     * @return list<array<string, mixed>> delegations the user has given or received, newest first
     */
    public function listFor(int $userId): array
    {
        return $this->db->query(
            "SELECT d.*, a.display_name AS delegator_name, b.display_name AS delegate_name, c.code AS company_code
             FROM inv_delegations d
             JOIN inv_users a ON a.id = d.delegator_id
             JOIN inv_users b ON b.id = d.delegate_id
             LEFT JOIN companies c ON c.id = d.company_id
             WHERE d.delegator_id = ? OR d.delegate_id = ?
             ORDER BY d.id DESC",
            [$userId, $userId],
        )->getResultArray();
    }

    /**
     * @return list<array<string, mixed>> all delegations (admin view)
     */
    public function listAll(): array
    {
        return $this->db->query(
            "SELECT d.*, a.display_name AS delegator_name, b.display_name AS delegate_name, c.code AS company_code
             FROM inv_delegations d
             JOIN inv_users a ON a.id = d.delegator_id
             JOIN inv_users b ON b.id = d.delegate_id
             LEFT JOIN companies c ON c.id = d.company_id
             ORDER BY d.active DESC, d.ends_at DESC LIMIT 300",
        )->getResultArray();
    }

    /**
     * @throws WorkflowException when the delegation is not allowed
     */
    public function create(int $actorId, int $delegatorId, int $delegateId, ?int $companyId, string $startsAt, string $endsAt, ?string $reason): int
    {
        $isAdmin = (new AdminAccess($this->db, $this->audit))->canManage($actorId);
        if ($actorId !== $delegatorId && ! $isAdmin) {
            throw new WorkflowException('You can only delegate your own approvals.');
        }
        if ($delegatorId === $delegateId) {
            throw new WorkflowException('You cannot delegate to yourself.');
        }

        $start = strtotime($startsAt);
        $end   = strtotime($endsAt);
        if ($start === false || $end === false) {
            throw new WorkflowException('Enter valid start and end dates.');
        }
        if ($end <= $start) {
            throw new WorkflowException('The end must be after the start.');
        }

        $delegate = $this->db->table('inv_users')->where('id', $delegateId)->where('active', 1)->get()->getRowArray();
        if ($delegate === null) {
            throw new WorkflowException('Choose an active user to delegate to.');
        }
        if ($this->db->table('inv_users')->where('id', $delegatorId)->countAllResults() === 0) {
            throw new WorkflowException('Delegator not found.');
        }
        if ($companyId !== null) {
            if ($this->db->table('companies')->where('id', $companyId)->countAllResults() === 0) {
                throw new WorkflowException('Company not found.');
            }
            if (! $this->scope->canViewCompany($delegateId, $companyId)) {
                throw new WorkflowException('That user cannot see this company, so they cannot approve for it. Add them to the company first.');
            }
        }

        $this->db->table('inv_delegations')->insert([
            'delegator_id' => $delegatorId, 'delegate_id' => $delegateId, 'company_id' => $companyId,
            'starts_at' => date('Y-m-d H:i:s', $start), 'ends_at' => date('Y-m-d H:i:s', $end),
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            'active' => 1, 'created_by' => $actorId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->audit->log('delegation.created', $actorId, 'delegation', $id, ['delegator' => $delegatorId, 'delegate' => $delegateId, 'company' => $companyId]);

        return $id;
    }

    public function revoke(int $actorId, int $delegationId): void
    {
        $row = $this->db->table('inv_delegations')->where('id', $delegationId)->get()->getRowArray();
        if ($row === null) {
            throw new WorkflowException('Delegation not found.');
        }

        $isAdmin = (new AdminAccess($this->db, $this->audit))->canManage($actorId);
        if ((int) $row['delegator_id'] !== $actorId && ! $isAdmin) {
            throw new WorkflowException('You can only end your own delegations.');
        }

        $this->db->table('inv_delegations')->where('id', $delegationId)->update(['active' => 0]);
        $this->audit->log('delegation.revoked', $actorId, 'delegation', $delegationId);
    }
}
