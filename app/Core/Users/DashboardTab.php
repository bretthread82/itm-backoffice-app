<?php

declare(strict_types=1);

namespace App\Core\Users;

use App\Core\Dashboard\DashboardTab as DashboardTabContract;

/**
 * The people tab on the dashboard: profile change requests waiting for a decision.
 */
final class DashboardTab implements DashboardTabContract
{
    public function badge(int $userId): int
    {
        return db_connect()->table('profile_change_requests')->where('status', 'pending')->countAllResults();
    }

    public function data(int $userId): array
    {
        $pending = db_connect()->table('profile_change_requests r')
            ->select('r.id, r.field, r.reason, r.created_at, sp.display_name')
            ->join('staff_profiles sp', 'sp.user_id = r.user_id')
            ->where('r.status', 'pending')->orderBy('r.id')->limit(10)->get()->getResultArray();

        return ['pending' => $pending, 'total' => $this->badge($userId)];
    }
}
