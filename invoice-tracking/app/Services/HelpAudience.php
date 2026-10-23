<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * What a signed-in user can actually do, so the in-app manual only shows what applies to them.
 *
 * Capabilities:
 *   submitter  may raise invoices for at least one active company
 *   approver   is, or may stand in as, someone who approves or processes invoices
 *   group      group-level user (separate from admin: an admin is not automatically group-level)
 *   admin      administrator
 *
 * Content marked with a list of capabilities ("needs") is shown when the user holds ANY of them;
 * content with no list is for everyone. This only decides what the manual explains. The real
 * permission checks stay in WorkflowEngine and AccessScope.
 */
final class HelpAudience
{
    /** Which capabilities each notification event is relevant to. Empty = everyone. */
    public const EVENT_NEEDS = [
        'invoice.submitted'     => ['approver'],
        'invoice.received'      => ['submitter'],
        'invoice.step_approved' => ['submitter'],
        'invoice.processed'     => ['submitter'],
        'invoice.paid'          => ['submitter'],
        'invoice.rejected'      => ['submitter'],
        'invoice.returned'      => ['submitter'],
        'invoice.resubmitted'   => ['approver'],
        'invoice.returned_step' => ['approver'],
        'invoice.override'      => ['approver', 'submitter'],
        'invoice.notify_step'   => [],
        'invoice.claimed'       => ['approver'],
        'invoice.released'      => ['approver'],
        'invoice.document_added' => ['submitter'],
        'invoice.reminder'      => ['approver'],
        'invoice.escalated'     => ['group'],
        'sync.failed'           => ['admin'],
        'digest'                => [],
    ];

    public function __construct(private ?BaseConnection $db = null, private ?AccessScope $scope = null)
    {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
    }

    /**
     * @return array{submitter: bool, approver: bool, group: bool, admin: bool, manager: bool, workflow: bool}
     */
    public function for(int $userId, bool $isAdmin, bool $isManager = false, bool $isWorkflow = false): array
    {
        return [
            'submitter' => $this->isSubmitter($userId),
            'approver'  => $this->isApprover($userId),
            'group'     => $this->scope->isGroupLevel($userId),
            'admin'     => $isAdmin,
            'manager'   => $isManager,
            'workflow'  => $isWorkflow,
        ];
    }

    /**
     * @param list<string>|null        $needs
     * @param array<string, bool>      $caps
     */
    public static function allows(?array $needs, array $caps): bool
    {
        if ($needs === null || $needs === []) {
            return true;
        }
        foreach ($needs as $need) {
            if (! empty($caps[$need])) {
                return true;
            }
        }

        return false;
    }

    private function isSubmitter(int $userId): bool
    {
        return $this->db->table('user_companies uc')
            ->join('companies c', 'c.id = uc.company_id')
            ->where(['uc.user_id' => $userId, 'uc.can_submit' => 1, 'c.active' => 1])
            ->countAllResults() > 0;
    }

    private function isApprover(int $userId): bool
    {
        // Only people who hold the approve right for an active company see approver help.
        $may = $this->db->table('user_companies uc')->join('companies c', 'c.id = uc.company_id')
            ->where(['uc.user_id' => $userId, 'uc.can_approve' => 1, 'c.active' => 1])->countAllResults() > 0;
        if (! $may) {
            return false;
        }

        $acts = ['approve', 'return', 'return_previous', 'reject', 'claim', 'release'];

        // Named on an active route.
        $named = $this->db->table('route_steps s')->join('routes r', 'r.id = s.route_id')
            ->where(['r.active' => 1, 's.assignee_user_id' => $userId])->whereIn('s.step_type', \App\Services\WorkflowEngine::ACTION_STEPS)
            ->countAllResults() > 0;
        if ($named) {
            return true;
        }

        // On a team used by an active route.
        $team = $this->db->table('team_members tm')
            ->join('teams t', 't.id = tm.team_id AND t.active = 1')
            ->join('route_steps s', 's.team_id = tm.team_id')->join('routes r', 'r.id = s.route_id AND r.active = 1')
            ->where('tm.user_id', $userId)->countAllResults() > 0;
        if ($team) {
            return true;
        }

        // Covering for someone now or later.
        $delegate = $this->db->table('delegations')
            ->where(['delegate_id' => $userId, 'active' => 1])->where('ends_at >=', date('Y-m-d H:i:s'))
            ->countAllResults() > 0;
        if ($delegate) {
            return true;
        }

        // Has acted on an approval step before.
        return $this->db->table('invoice_actions')->where('acted_by', $userId)->whereIn('action', $acts)->countAllResults() > 0;
    }
}
