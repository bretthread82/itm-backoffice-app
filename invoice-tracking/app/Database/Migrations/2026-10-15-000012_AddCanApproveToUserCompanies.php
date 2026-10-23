<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCanApproveToUserCompanies extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('user_companies', [
            'can_approve' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'can_submit'],
        ]);

        // Keep today's approvers working: grant the right to everyone who can already act for a company.
        $grant = static function ($db, string $where): void {
            $db->query("UPDATE user_companies uc SET uc.can_approve = 1 WHERE uc.can_approve = 0 AND ({$where})");
        };

        // Named on a route step.
        $grant($this->db, 'EXISTS (SELECT 1 FROM route_steps s JOIN routes r ON r.id = s.route_id
            WHERE s.assignee_user_id = uc.user_id AND r.company_id = uc.company_id)');
        // Member of a team used by a route step.
        $grant($this->db, 'EXISTS (SELECT 1 FROM route_steps s JOIN routes r ON r.id = s.route_id
            JOIN team_members m ON m.team_id = s.team_id
            WHERE m.user_id = uc.user_id AND r.company_id = uc.company_id)');
        // Delegate of someone named on a route step.
        $grant($this->db, 'EXISTS (SELECT 1 FROM delegations d JOIN route_steps s ON s.assignee_user_id = d.delegator_id
            JOIN routes r ON r.id = s.route_id
            WHERE d.delegate_id = uc.user_id AND r.company_id = uc.company_id
              AND (d.company_id IS NULL OR d.company_id = uc.company_id))');

        // Named on the snapshot of an invoice still in flight (the route may have changed since).
        $inFlight = $this->db->table('invoices')->select('company_id, route_snapshot')
            ->whereIn('status', ['in_approval', 'approved'])->get()->getResultArray();
        foreach ($inFlight as $inv) {
            $snap = json_decode((string) ($inv['route_snapshot'] ?? ''), true);
            foreach (is_array($snap) ? ($snap['steps'] ?? []) : [] as $step) {
                if (! empty($step['user_id'])) {
                    $this->db->table('user_companies')->where(['user_id' => (int) $step['user_id'], 'company_id' => (int) $inv['company_id']])
                        ->update(['can_approve' => 1]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('user_companies', 'can_approve');
    }
}
