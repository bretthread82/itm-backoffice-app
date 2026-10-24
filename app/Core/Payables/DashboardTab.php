<?php

declare(strict_types=1);

namespace App\Core\Payables;

use App\Core\Dashboard\DashboardTab as DashboardTabContract;

/**
 * The invoices tab on the dashboard: what is waiting on the user, what came back to them, and what is due.
 * Everything comes from the existing services, so company scoping is already applied.
 */
final class DashboardTab implements DashboardTabContract
{
    public function badge(int $userId): int
    {
        return NavCounts::for($userId)['waiting'];
    }

    public function data(int $userId): array
    {
        $queue = new ApprovalQueue();
        $rows  = (new ReportService())->outstanding($userId);
        $mine  = $queue->mine($userId);

        return [
            'waiting'   => $queue->pendingFor($userId),
            'mine'      => $mine,
            'agg'       => ReportService::aggregate($rows),
            'dueSoon'   => ReportService::dueWithin($rows, 7),
            'canSubmit' => db_connect()->table('user_companies')->where(['user_id' => $userId, 'can_submit' => 1])->countAllResults() > 0,
            'activity'  => (new ActivityFeed())->recent($userId, 0, 6),
        ];
    }
}
