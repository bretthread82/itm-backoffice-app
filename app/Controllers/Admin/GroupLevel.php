<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\StaffProfileModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Group-level users see every company. This is a boundary, not a role: an administrator is not
 * automatically group-level.
 */
class GroupLevel extends BaseController
{
    public function index(): string
    {
        $granted = db_connect()->table('group_level_users g')
            ->select('g.user_id, g.granted_at, sp.first_name, sp.last_name, sp.display_name')
            ->join('staff_profiles sp', 'sp.user_id = g.user_id AND sp.deleted_at IS NULL', 'left')
            ->orderBy('sp.last_name')->get()->getResultArray();

        $grantedIds = array_map(static fn (array $r): int => (int) $r['user_id'], $granted);
        $candidates = array_values(array_filter(
            model(StaffProfileModel::class)->listing(['status' => 'active'])->orderBy('sp.last_name')->get()->getResultArray(),
            static fn (array $r): bool => ! in_array((int) $r['user_id'], $grantedIds, true),
        ));

        return view('admin/companies/group_level', ['title' => 'Group-level users', 'granted' => $granted, 'candidates' => $candidates]);
    }

    public function add(): RedirectResponse
    {
        $userId = (int) $this->request->getPost('user_id');
        $found  = $userId > 0 ? model(StaffProfileModel::class)->listing(['status' => 'active'])->where('sp.user_id', $userId)->get()->getRowArray() : null;

        if (! $found) {
            return redirect()->back()->with('error', 'Choose an active user.');
        }

        service('memberships')->grantGroupLevel($userId, (int) auth()->id());
        service('audit')->log('user.group_level_granted', 'user', $userId);

        return redirect()->to('admin/group-level')->with('message', 'Group-level access granted.');
    }

    public function remove(int $userId): RedirectResponse
    {
        service('memberships')->revokeGroupLevel($userId);
        service('audit')->log('user.group_level_revoked', 'user', $userId);

        return redirect()->to('admin/group-level')->with('message', 'Group-level access removed.');
    }
}
