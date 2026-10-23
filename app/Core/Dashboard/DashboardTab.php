<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

/**
 * What a module contributes to the dashboard. Declared in the module's 'dashboard' registry entry;
 * the tab's view is app/Views/dashboard/tabs/<module key>.php and receives data()'s array.
 */
interface DashboardTab
{
    /** How many things need this user's attention (shown on the tab and on Today). */
    public function badge(int $userId): int;

    /**
     * Everything the tab's view needs. Only called for the tab being shown.
     *
     * @return array<string, mixed>
     */
    public function data(int $userId): array;
}
