<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Tenancy\AccessScope;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Hands a leaving user's customer invoice work to a replacement (resignation, dismissal, long leave) and can switch off
 * the account in the same step. Needs receivables.manage; switching the account off also needs users.delete. Everything
 * is audited.
 *
 * What moves:
 *  - approval and issue steps still ahead on in-flight invoices (the route snapshot is edited for those steps only, so
 *    steps already acted on keep their history, and a note records the change);
 *  - the same user on the routes themselves, so future invoices go to the replacement;
 *  - unfinished invoices they raised (drafts, in approval, returned, rejected);
 *  - invoices they had accepted for a team, which go back to the team;
 *  - their delegations, given or received, which are ended.
 * When the account is switched off they also leave every team.
 *
 * What does not move: invoices already issued (collection belongs to whoever holds the collect right for the company),
 * and the history: ar_actions, ar_notes and ar_payments are never rewritten. Per-company rights are not copied: the
 * replacement must already hold the ones the work needs, otherwise the work would stall on them.
 */
final class ArUserOffboarding
{
    /** Invoices the raiser still has to do something with, or is waiting on. */
    private const OPEN_STATUSES = ['draft', 'in_approval', 'approved', 'returned', 'rejected'];

    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?ArAudit $audit = null,
    ) {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
        $this->audit ??= new ArAudit();
    }

    /**
     * What a transfer would move, and which company rights the replacement will need.
     *
     * @return array{
     *     steps: list<array{invoice_id: int, company_id: int, current: bool}>, route_steps: int, submitted: int, claims: int,
     *     delegations: int, teams: int, company_ids: list<int>, need_approve: list<int>, need_submit: list<int>
     * }
     */
    public function preview(int $fromId): array
    {
        $steps = [];
        foreach ($this->inFlight() as $inv) {
            foreach ((array) (json_decode((string) $inv['route_snapshot'], true)['steps'] ?? []) as $s) {
                if ($this->movable($s, $fromId, $inv)) {
                    $steps[] = ['invoice_id' => (int) $inv['id'], 'company_id' => (int) $inv['company_id'], 'current' => (int) $s['order'] === (int) $inv['current_step_order']];
                }
            }
        }

        $routeCompanies = array_map('intval', array_column(
            $this->db->table('ar_route_steps s')->select('r.company_id')->join('ar_routes r', 'r.id = s.route_id')->where('s.assignee_user_id', $fromId)->get()->getResultArray(),
            'company_id',
        ));
        $submitted = array_map('intval', array_column(
            $this->db->table('ar_invoices')->select('company_id')->where('submitted_by', $fromId)->whereIn('status', self::OPEN_STATUSES)->get()->getResultArray(),
            'company_id',
        ));

        $needApprove = array_values(array_unique([...array_column($steps, 'company_id'), ...$routeCompanies]));
        $needSubmit  = array_values(array_unique($submitted));

        return [
            'steps'        => $steps,
            'route_steps'  => count($routeCompanies),
            'submitted'    => count($submitted),
            'claims'       => $this->db->table('ar_step_claims')->where('claimed_by', $fromId)->where('released_at IS NULL')->countAllResults(),
            'delegations'  => $this->db->table('ar_delegations')->where('active', 1)->groupStart()->where('delegator_id', $fromId)->orWhere('delegate_id', $fromId)->groupEnd()->countAllResults(),
            'teams'        => $this->db->table('ar_team_members')->where('user_id', $fromId)->countAllResults(),
            'company_ids'  => array_values(array_unique([...$needApprove, ...$needSubmit])),
            'need_approve' => $needApprove,
            'need_submit'  => $needSubmit,
        ];
    }

    /**
     * The company rights the replacement lacks for this work, as sentences, or an empty list when they have them all.
     *
     * @param array<string, mixed> $plan from preview()
     *
     * @return list<string>
     */
    public function missingFor(int $toId, array $plan): array
    {
        $missing = [];
        $code    = fn (int $companyId): string => (string) ($this->db->table('companies')->select('code')->where('id', $companyId)->get()->getRowArray()['code'] ?? $companyId);

        foreach ($plan['company_ids'] as $companyId) {
            if (! $this->scope->canViewCompany($toId, $companyId)) {
                $missing[] = 'membership of ' . $code($companyId);
            }
        }
        foreach ($plan['need_approve'] as $companyId) {
            if (! $this->scope->can($toId, $companyId, 'receivables.approve')) {
                $missing[] = '"Can approve and issue" for ' . $code($companyId);
            }
        }
        foreach ($plan['need_submit'] as $companyId) {
            if (! $this->scope->can($toId, $companyId, 'receivables.submit')) {
                $missing[] = '"Can raise" for ' . $code($companyId);
            }
        }

        return $missing;
    }

    /**
     * @return array{steps: int, route_steps: int, submitted: int, claims: int, delegations: int, teams: int, disabled: bool}
     *
     * @throws RuntimeException when the transfer is not allowed; nothing is changed
     */
    public function transfer(int $actorId, int $fromId, int $toId, bool $disable): array
    {
        $perms = service('permissions');

        if (! $perms->can($actorId, 'receivables.manage')) {
            throw new RuntimeException('You do not have permission to do that.');
        }
        if ($perms->isSuper($fromId) && ! $perms->isSuper($actorId)) {
            throw new RuntimeException('Only a Super Admin can change a Super Admin.');
        }
        if ($disable && ! $perms->can($actorId, 'users.delete')) {
            throw new RuntimeException('You do not have permission to switch off accounts. Hand over the work without that option, or ask someone who can.');
        }
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

        $plan    = $this->preview($fromId);
        $missing = $this->missingFor($toId, $plan);
        if ($missing !== []) {
            throw new RuntimeException('The replacement is missing ' . implode(', ', $missing) . '. Give them these under Manage company access first, so the work does not stall.');
        }

        $fromName = (string) ($from['display_name'] ?: $from['username']);
        $toName   = (string) ($to['display_name'] ?: $to['username']);
        $now      = date('Y-m-d H:i:s');
        $current  = [];
        $left     = 0;

        $this->db->transBegin();
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
                    $this->db->table('ar_invoices')->where('id', $inv['id'])->update(['route_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
                    $this->db->table('ar_notes')->insert([
                        'invoice_id' => $inv['id'], 'user_id' => $actorId, 'kind' => 'note', 'created_at' => $now,
                        'body' => "Approver changed from {$fromName} to {$toName} by an administrator.",
                    ]);
                }
            }

            // Future invoices: the route steps themselves, including the name shown on them.
            $this->db->table('ar_route_steps')->where('assignee_user_id', $fromId)->update(['assignee_user_id' => $toId, 'assignee_label' => $toName]);
            $this->db->table('ar_invoices')->where('submitted_by', $fromId)->whereIn('status', self::OPEN_STATUSES)->update(['submitted_by' => $toId, 'updated_at' => $now]);
            $this->db->table('ar_delegations')->where('active', 1)->groupStart()->where('delegator_id', $fromId)->orWhere('delegate_id', $fromId)->groupEnd()->update(['active' => 0]);
            $this->releaseClaims($fromId, $actorId, $now);

            $this->audit->log('ar.work_transferred', $actorId, 'user', $fromId, [
                'to' => $toId, 'steps' => count($plan['steps']), 'route_steps' => $plan['route_steps'], 'submitted' => $plan['submitted'],
                'claims_released' => $plan['claims'], 'delegations_ended' => $plan['delegations'],
            ]);

            if ($disable) {
                // Leaving for good: off every team too.
                $left = (new ArTeamMembers($this->db))->removeFromAll($fromId, $actorId);
                if ($left > 0) {
                    $this->audit->log('ar.left_teams', $actorId, 'user', $fromId, ['teams' => $left]);
                }
            }

            if (! $this->db->transStatus()) {
                throw new RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof RuntimeException ? $e : new RuntimeException('The transfer could not be completed. Nothing was changed.', 0, $e);
        }

        // Switching the account off is a platform action with its own rules (not the last Super Admin, and so on). It
        // runs after the work has moved: if it refuses, the work is already safely with the replacement.
        $disabled = false;
        if ($disable && (bool) $from['active']) {
            service('userService')->setStatus($fromId, 'inactive');
            $disabled = true;
        }

        $this->tellReplacement($current, $toId, $actorId, $fromName);

        return [
            'steps' => count($plan['steps']), 'route_steps' => $plan['route_steps'], 'submitted' => $plan['submitted'],
            'claims' => $plan['claims'], 'delegations' => $plan['delegations'], 'teams' => $left, 'disabled' => $disabled,
        ];
    }

    /**
     * Gives back what $userId had accepted for a team step, recording it in the history.
     */
    private function releaseClaims(int $userId, int $actorId, string $now): void
    {
        foreach ($this->db->table('ar_step_claims')->where('claimed_by', $userId)->where('released_at IS NULL')->get()->getResultArray() as $c) {
            $this->db->table('ar_step_claims')->where('id', $c['id'])->update(['released_at' => $now, 'released_by' => $actorId]);
            $this->db->table('ar_actions')->insert([
                'invoice_id' => (int) $c['invoice_id'], 'step_order' => (int) $c['step_order'], 'acted_by' => $actorId,
                'on_behalf_of' => $userId, 'acted_as' => 'team', 'action' => 'release', 'note_id' => null, 'acted_at' => $now,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inFlight(): array
    {
        return $this->db->table('ar_invoices')->select('id, company_id, route_snapshot, current_step_order')
            ->whereIn('status', ReceivablesEngine::ACTIVE)->where('current_step_order IS NOT NULL', null, false)
            ->get()->getResultArray();
    }

    /**
     * A step still ahead (or current) and held by this user as a named person. Team steps stay with the team.
     *
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
            $notify = new ArNotifier($this->db);
            foreach ($invoiceIds as $invoiceId) {
                $notify->dispatch('ar.submitted', $invoiceId, [$toId], ['actor_id' => $actorId, 'actor_name' => "an administrator (reassigned from {$fromName})"]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Transfer notification failed: ' . $e->getMessage());
        }
    }
}
