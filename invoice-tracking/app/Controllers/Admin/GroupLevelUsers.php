<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AdminAccess;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * The single group-level security boundary. Only administrators can open this screen
 * (the admin filter) and AdminAccess re-checks the actor on every change.
 */
class GroupLevelUsers extends BaseController
{
    public function index(): string
    {
        $db      = db_connect();
        $members = $db->query(
            'SELECT u.id, u.display_name, u.username, u.active, g.granted_at,
                    b.display_name AS granted_by_name
             FROM group_level_users g
             JOIN users u ON u.id = g.user_id
             LEFT JOIN users b ON b.id = g.granted_by
             ORDER BY u.display_name',
        )->getResultArray();

        $candidates = $db->query(
            'SELECT id, display_name, username FROM users
             WHERE active = 1 AND id NOT IN (SELECT user_id FROM group_level_users)
             ORDER BY display_name',
        )->getResultArray();

        return view('admin/group_level/index', [
            'title'      => 'Group-level users',
            'members'    => $members,
            'candidates' => $candidates,
        ]);
    }

    public function add(): RedirectResponse
    {
        $userId = (int) $this->request->getPost('user_id');
        if ($userId < 1) {
            return redirect()->back()->with('error', 'Choose a user.');
        }

        try {
            (new AdminAccess())->grantGroupLevel((int) auth()->id(), $userId);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/group-level')->with('message', 'Group-level access granted.');
    }

    public function remove(int $userId): RedirectResponse
    {
        try {
            (new AdminAccess())->revokeGroupLevel((int) auth()->id(), $userId);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/group-level')->with('message', 'Group-level access removed.');
    }
}
