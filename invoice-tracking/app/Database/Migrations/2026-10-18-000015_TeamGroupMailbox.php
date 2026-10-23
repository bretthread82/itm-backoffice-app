<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Teams get an optional group mailbox, and the "shared mailbox" assignee kind is retired: every
 * shared-mailbox step becomes a team (named after the mailbox, group mailbox = its address, no
 * members yet) so notifications keep reaching the same address. Invoices in flight have the same
 * change applied to their route snapshot.
 */
class TeamGroupMailbox extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('teams', [
            'group_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'description'],
        ]);

        /** @var array<string, int> $teams lower-cased mailbox name => team id */
        $teams = [];
        $team  = function (string $label, ?string $email) use (&$teams): int {
            $label = mb_substr(trim($label) !== '' ? trim($label) : ($email ?? 'Shared mailbox'), 0, 120);
            $key   = mb_strtolower($label);
            if (isset($teams[$key])) {
                return $teams[$key];
            }

            $row = $this->db->table('teams')->where('LOWER(name)', $key)->get()->getRowArray();
            if ($row !== null) {
                if ($email !== null && trim((string) $row['group_email']) === '') {
                    $this->db->table('teams')->where('id', (int) $row['id'])->update(['group_email' => $email]);
                }

                return $teams[$key] = (int) $row['id'];
            }

            $this->db->table('teams')->insert([
                'name' => $label, 'group_email' => $email, 'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            echo "  Created team \"{$label}\" from a shared mailbox: add its members under Admin > Teams.\n";

            return $teams[$key] = (int) $this->db->insertID();
        };

        $email = static function ($raw): ?string {
            $e = strtolower(trim((string) $raw));

            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
        };

        $steps = $this->db->table('route_steps')->where('assignee_kind', 'shared_mailbox')->get()->getResultArray();
        foreach ($steps as $s) {
            $teamId = $team((string) $s['assignee_label'], $email($s['assignee_email']));
            $this->db->table('route_steps')->where('id', (int) $s['id'])->update([
                'assignee_kind' => 'team', 'team_id' => $teamId, 'assignee_email' => null,
            ]);
        }

        $inFlight = $this->db->table('invoices')->select('id, route_snapshot')
            ->whereIn('status', ['in_approval', 'approved'])->get()->getResultArray();
        foreach ($inFlight as $inv) {
            $snap = json_decode((string) ($inv['route_snapshot'] ?? ''), true);
            if (! is_array($snap) || ! isset($snap['steps']) || ! is_array($snap['steps'])) {
                continue;
            }
            $changed = false;
            foreach ($snap['steps'] as &$step) {
                if (($step['assignee_kind'] ?? '') !== 'shared_mailbox') {
                    continue;
                }
                $step['team_id']       = $team((string) ($step['label'] ?? ''), $email($step['email'] ?? null));
                $step['assignee_kind'] = 'team';
                $step['email']         = null;
                $changed               = true;
            }
            unset($step);
            if ($changed) {
                $this->db->table('invoices')->where('id', (int) $inv['id'])->update(['route_snapshot' => json_encode($snap)]);
            }
        }
    }

    public function down(): void
    {
        // Converted steps stay teams; only the column goes.
        $this->forge->dropColumn('teams', 'group_email');
    }
}
