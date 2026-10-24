<?php

declare(strict_types=1);

namespace App\Core\Payables;

use App\Core\Tenancy\AccessScope;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Hands a leaving user's work to a replacement (resignation, dismissal, long leave) and can disable
 * the account in the same step. Admin only; everything is audited.
 *
 * What moves: approval steps still ahead on in-flight invoices (the route snapshot is edited for
 * those steps only; steps already acted on keep their history), the same user on future routes,
 * and unfinished invoices they submitted. Their delegations are ended. Completed invoices,
 * invoice_actions and invoice_notes are never rewritten.
 */
final class UserOffboarding
{
    /** Invoices the submitter still has to do something with, or is waiting on. */
    private const OPEN_STATUSES = ['draft', 'in_approval', 'approved', 'returned', 'rejected'];

    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?InvoiceAudit $audit = null,
        private ?AdminAccess $admin = null,
    ) {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
        $this->audit ??= new InvoiceAudit($this->db);
        $this->admin ??= new AdminAccess($this->db, $this->audit);
    }

    /**
     * What a transfer would move.
     *
     * @return array{steps: list<array{invoice_id: int, company_id: int, current: bool}>, route_steps: int, submitted: int, delegations: int, company_ids: list<int>}
     */
    public function preview(int $fromId): array
    {
        $steps = [];
        foreach ($this->inFlight() as $inv) {
            $snap = json_decode((string) $inv['route_snapshot'], true);
            foreach ((array) ($snap['steps'] ?? []) as $s) {
                if ($this->movable($s, $fromId, $inv)) {
                    $steps[] = ['invoice_id' => (int) $inv['id'], 'company_id' => (int) $inv['company_id'], 'current' => (int) $s['order'] === (int) $inv['current_step_order']];
                }
            }
        }

        $routeRows = $this->db->table('inv_route_steps s')->select('r.company_id')->join('inv_routes r', 'r.id = s.route_id')
            ->where('s.assignee_user_id', $fromId)->get()->getResultArray();
        $submitted = $this->db->table('inv_invoices')->select('company_id')->where('submitted_by', $fromId)->whereIn('status', self::OPEN_STATUSES)->get()->getResultArray();

        $companies = array_merge(
            array_column($steps, 'company_id'),
            array_map('intval', array_column($routeRows, 'company_id')),
            array_map('intval', array_column($submitted, 'company_id')),
        );

        return [
            'steps'       => $steps,
            'route_steps' => count($routeRows),
            'submitted'   => count($submitted),
            'delegations' => $this->db->table('inv_delegations')->where('active', 1)->groupStart()->where('delegator_id', $fromId)->orWhere('delegate_id', $fromId)->groupEnd()->countAllResults(),
            'company_ids' => array_values(array_unique(array_map('intval', $companies))),
        ];
    }

    /**
     * @return array{steps: int, route_steps: int, submitted: int, delegations: int}
     *
     * @throws RuntimeException when the transfer is not allowed
     */
    public function transfer(int $actorId, int $fromId, int $toId, bool $disable): array
    {
        $this->admin->requireCanAdminister($actorId, $fromId);
        if ($fromId === $toId) {
            throw new RuntimeException('Choose a different user to take over.');
        }
        $from = $this->db->table('inv_users')->where('id', $fromId)->get()->getRowArray();
        $to   = $this->db->table('inv_users')->where('id', $toId)->where('active', 1)->get()->getRowArray();
        if ($from === null) {
            throw new RuntimeException('User not found.');
        }
        if ($to === null) {
            throw new RuntimeException('Choose an active user to take over.');
        }

        // The replacement must be able to see every company the work belongs to, otherwise it would vanish from their lists.
        $plan    = $this->preview($fromId);
        $missing = [];
        foreach ($plan['company_ids'] as $companyId) {
            if (! $this->scope->canViewCompany($toId, $companyId)) {
                $missing[] = (string) ($this->db->table('companies')->select('code')->where('id', $companyId)->get()->getRowArray()['code'] ?? $companyId);
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('The replacement cannot see these companies: ' . implode(', ', $missing) . '. Add them to the companies first.');
        }

        $fromName = (string) ($from['display_name'] ?: $from['username']);
        $toName   = (string) ($to['display_name'] ?: $to['username']);
        $now      = date('Y-m-d H:i:s');
        $current  = [];

        $this->db->transException(false)->transStart();

        try {
        foreach ($this->inFlight() as $inv) {
            $snap = json_decode((string) $inv['route_snapshot'], true);
            $hit  = false;
            foreach ($snap['steps'] as &$s) {
                if (! $this->movable($s, $fromId, $inv)) {
                    continue;
                }
                $s['user_id'] = $toId;
                if (($s['label'] ?? '') !== '') {
                    $s['label'] = $toName;
                }
                $hit = true;
                if ((int) $s['order'] === (int) $inv['current_step_order']) {
                    $current[] = (int) $inv['id'];
                }
            }
            unset($s);

            if ($hit) {
                $this->db->table('inv_invoices')->where('id', $inv['id'])->update(['route_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
                $this->db->table('inv_notes')->insert([
                    'invoice_id' => $inv['id'], 'user_id' => $actorId, 'kind' => 'note', 'created_at' => $now,
                    'body' => "Approver changed from {$fromName} to {$toName} by an administrator.",
                ]);
            }
        }

        $this->db->table('inv_route_steps')->where('assignee_user_id', $fromId)->update(['assignee_user_id' => $toId]);
        $this->db->table('inv_invoices')->where('submitted_by', $fromId)->whereIn('status', self::OPEN_STATUSES)->update(['submitted_by' => $toId, 'updated_at' => $now]);
        $this->db->table('inv_delegations')->where('active', 1)->groupStart()->where('delegator_id', $fromId)->orWhere('delegate_id', $fromId)->groupEnd()->update(['active' => 0]);

        $this->audit->log('user.work_transferred', $actorId, 'user', $fromId, [
            'to' => $toId, 'steps' => count($plan['steps']), 'route_steps' => $plan['route_steps'], 'submitted' => $plan['submitted'], 'delegations_ended' => $plan['delegations'],
        ]);

        if ($disable) {
            // Leaving for good: off every team, and anything they had accepted goes back to the team.
            $left = (new TeamMembers($this->db))->removeFromAll($fromId, $actorId);
            if ($left > 0) {
                $this->audit->log('user.left_teams', $actorId, 'user', $fromId, ['teams' => $left]);
            }
        }
        if ($disable && (bool) $from['active']) {
            $this->admin->setActive($actorId, $fromId, false);
        }
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof RuntimeException ? $e : new RuntimeException('The transfer could not be completed. Nothing was changed.', 0, $e);
        }

        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new RuntimeException('The transfer could not be completed. Nothing was changed.');
        }

        $this->tellReplacement($current, $toId, $actorId, $fromName);

        return ['steps' => count($plan['steps']), 'route_steps' => $plan['route_steps'], 'submitted' => $plan['submitted'], 'delegations' => $plan['delegations']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inFlight(): array
    {
        return $this->db->table('inv_invoices')->select('id, company_id, route_snapshot, current_step_order')
            ->whereIn('status', WorkflowEngine::ACTIVE)->where('current_step_order IS NOT NULL', null, false)
            ->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $inv
     */
    private function movable(array $step, int $userId, array $inv): bool
    {
        return ($step['assignee_kind'] ?? 'user') === 'user'
            && (int) ($step['user_id'] ?? 0) === $userId
            && (int) $step['order'] >= (int) $inv['current_step_order'];
    }

    /**
     * Email problems never block the transfer.
     *
     * @param list<int> $invoiceIds
     */
    private function tellReplacement(array $invoiceIds, int $toId, int $actorId, string $fromName): void
    {
        try {
            $notify = new InvoiceNotifier($this->db);
            foreach ($invoiceIds as $invoiceId) {
                $notify->dispatch('payables.submitted', $invoiceId, [$toId], ['actor_id' => $actorId, 'actor_name' => "an administrator (reassigned from {$fromName})"]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Transfer notification failed: ' . $e->getMessage());
        }
    }
}
