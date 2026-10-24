<?php

declare(strict_types=1);

namespace App\Controllers\Receivables\Admin;

use App\Controllers\BaseController;
use App\Core\Receivables\ArAudit;
use App\Core\Receivables\ArTeamMembers;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Teams: named groups of users that can be assigned to a route step. One member accepts the
 * invoice and then acts on it. Teams are global; invoice visibility still follows company membership.
 */
class Teams extends BaseController
{
    public function index(): string
    {
        $teams = db_connect()->query(
            'SELECT t.id, t.name, t.description, t.group_email, t.active,
                    (SELECT COUNT(*) FROM ar_team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = t.id AND u.active = 1) AS member_count,
                    (SELECT COUNT(*) FROM ar_route_steps s JOIN ar_routes r ON r.id = s.route_id WHERE s.team_id = t.id AND r.active = 1) AS route_count
             FROM ar_teams t ORDER BY t.active DESC, t.name',
        )->getResultArray();

        return view('receivables/admin/teams/index', ['title' => 'Teams', 'teams' => $teams]);
    }

    public function new(): string
    {
        return $this->form(['name' => '', 'description' => '', 'group_email' => '', 'active' => 1], [], null);
    }

    public function create(): RedirectResponse
    {
        $error = $this->invalid(null);
        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $db = db_connect();
        $db->table('ar_teams')->insert([
            'name' => $this->name(), 'description' => $this->description(), 'group_email' => $this->groupEmail(), 'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();

        (new ArTeamMembers($db))->sync($id, $this->postedMembers(), (int) auth()->id());
        (new ArAudit())->log('ar.team_created', (int) auth()->id(), 'ar_team', $id, ['members' => count($this->postedMembers())]);

        return redirect()->to('receivables-admin/teams/' . $id . '/edit')->with('message', 'Team created.');
    }

    public function edit(int $id): string
    {
        $team = $this->team($id);

        return $this->form($team, (new ArTeamMembers())->memberIds($id), $id);
    }

    public function update(int $id): RedirectResponse
    {
        $team  = $this->team($id);
        $error = $this->invalid($id);
        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $active = (bool) $this->request->getPost('active');
        if (! $active && $team['active'] && ($used = (new ArTeamMembers())->activeRouteCount($id)) > 0) {
            return redirect()->back()->withInput()->with('error', "This team is used by {$used} active route step(s). Change those routes before switching the team off.");
        }

        $db = db_connect();
        $db->table('ar_teams')->where('id', $id)->update([
            'name' => $this->name(), 'description' => $this->description(), 'group_email' => $this->groupEmail(), 'active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $changes = (new ArTeamMembers($db))->sync($id, $this->postedMembers(), (int) auth()->id());
        (new ArAudit())->log('ar.team_updated', (int) auth()->id(), 'ar_team', $id, ['active' => $active] + $changes);

        return redirect()->to('receivables-admin/teams/' . $id . '/edit')->with('message', 'Team saved.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $team
     * @param list<int>            $memberIds
     */
    private function form(array $team, array $memberIds, ?int $id): string
    {
        $users = db_connect()->query(
            "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.active FROM inv_users u ORDER BY name",
        )->getResultArray();

        return view('receivables/admin/teams/form', [
            'title'     => $id === null ? 'New team' : 'Team: ' . $team['name'],
            'team'      => $team,
            'id'        => $id,
            'users'     => $users,
            'memberIds' => $memberIds,
            'routes'    => $id === null ? 0 : (new ArTeamMembers())->activeRouteCount($id),
        ]);
    }

    private function name(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $this->request->getPost('name')));
    }

    private function description(): ?string
    {
        $d = trim((string) $this->request->getPost('description'));

        return $d === '' ? null : mb_substr($d, 0, 255);
    }

    /** The team's group mailbox, lower-cased, or null when none was entered. */
    private function groupEmail(): ?string
    {
        $e = strtolower(trim((string) $this->request->getPost('group_email')));

        return $e === '' ? null : $e;
    }

    /**
     * @return list<int>
     */
    private function postedMembers(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $this->request->getPost('members')), static fn (int $i): bool => $i > 0)));
    }

    private function invalid(?int $id): ?string
    {
        $name = $this->name();
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Give the team a name (up to 120 characters).';
        }

        $builder = db_connect()->table('ar_teams')->where('LOWER(name)', mb_strtolower($name));
        if ($id !== null) {
            $builder->where('id !=', $id);
        }
        if ($builder->countAllResults() > 0) {
            return 'A team with that name already exists.';
        }

        $email = $this->groupEmail();
        if ($email !== null && (strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return 'The group mailbox is not a valid email address.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function team(int $id): array
    {
        $team = db_connect()->table('ar_teams')->where('id', $id)->get()->getRowArray();
        if ($team === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $team;
    }
}
