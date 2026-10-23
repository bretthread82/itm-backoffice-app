<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use CodeIgniter\Database\BaseConnection;

/**
 * Who is on which team, and what happens to invoices a person has accepted when they leave one.
 */
final class TeamMembers
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @return list<int>
     */
    public function memberIds(int $teamId): array
    {
        $rows = $this->db->table('inv_team_members')->select('user_id')->where('team_id', $teamId)->orderBy('user_id')->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    /**
     * Makes the team's members exactly $wanted. Anyone removed loses the invoices they had accepted
     * for this team: those go back to the team (recorded in the action history).
     *
     * @param list<int> $wanted
     *
     * @return array{added: int, removed: int}
     */
    public function sync(int $teamId, array $wanted, ?int $actorId = null): array
    {
        $wanted = array_values(array_unique($wanted));
        if ($wanted !== []) {
            $wanted = array_map(static fn (array $r): int => (int) $r['id'], $this->db->table('inv_users')->select('id')->whereIn('id', $wanted)->get()->getResultArray());
        }

        $current = $this->memberIds($teamId);
        $add     = array_values(array_diff($wanted, $current));
        $remove  = array_values(array_diff($current, $wanted));

        foreach ($add as $uid) {
            $this->db->table('inv_team_members')->insert(['team_id' => $teamId, 'user_id' => $uid]);
        }
        if ($remove !== []) {
            $this->db->table('inv_team_members')->where('team_id', $teamId)->whereIn('user_id', $remove)->delete();
            foreach ($remove as $uid) {
                $this->releaseClaims($uid, $teamId, $actorId ?? $uid);
            }
        }

        return ['added' => count($add), 'removed' => count($remove)];
    }

    /**
     * Takes the user off every team and returns what they had accepted to the teams.
     * Used when an account is disabled or its work is handed over.
     */
    public function removeFromAll(int $userId, ?int $actorId = null): int
    {
        $teamIds = array_map(static fn (array $r): int => (int) $r['team_id'], $this->db->table('inv_team_members')->select('team_id')->where('user_id', $userId)->get()->getResultArray());
        $this->db->table('inv_team_members')->where('user_id', $userId)->delete();
        $this->releaseClaims($userId, null, $actorId ?? $userId);

        return count($teamIds);
    }

    /**
     * Active routes that currently use this team.
     */
    public function activeRouteCount(int $teamId): int
    {
        return $this->db->table('inv_route_steps s')->join('inv_routes r', 'r.id = s.route_id')
            ->where('s.team_id', $teamId)->where('r.active', 1)->countAllResults();
    }

    /**
     * Gives back what $userId had accepted (for one team, or all when $teamId is null).
     */
    private function releaseClaims(int $userId, ?int $teamId, int $actorId): void
    {
        $builder = $this->db->table('inv_step_claims')->where('claimed_by', $userId)->where('released_at IS NULL');
        if ($teamId !== null) {
            $builder->where('team_id', $teamId);
        }
        $open = $builder->get()->getResultArray();

        $now = date('Y-m-d H:i:s');
        foreach ($open as $c) {
            $this->db->table('inv_step_claims')->where('id', $c['id'])->update(['released_at' => $now, 'released_by' => $actorId]);
            $this->db->table('inv_actions')->insert([
                'invoice_id' => (int) $c['invoice_id'], 'step_order' => (int) $c['step_order'], 'acted_by' => $actorId,
                'on_behalf_of' => $userId, 'acted_as' => 'team', 'action' => 'release', 'note_id' => null, 'acted_at' => $now,
            ]);
        }
    }
}
