<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use Config\Backoffice;

/**
 * Builds the dashboard: a "Today" tab plus one tab per enabled module the user can use.
 */
final class DashboardService
{
    public const TODAY = 'today';

    /**
     * Module tabs this user may see, in order. A disabled module's permissions fail can(), so its tab drops out.
     *
     * @return array<string, array{label: string, icon: string, permission: string, order: int, provider: class-string}>
     */
    public function tabsFor(int $userId): array
    {
        $modules = service('modules');
        $perms   = service('permissions');
        $tabs    = [];

        foreach (config(Backoffice::class)->dashboardTabs() as $key => $tab) {
            if ($modules->enabled($key) && $perms->can($userId, $tab['permission'])) {
                $tabs[$key] = $tab;
            }
        }

        uasort($tabs, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $tabs;
    }

    /**
     * @param array<string, array<string, mixed>> $tabs from tabsFor()
     */
    public function resolve(array $tabs, ?string $requested): string
    {
        return $requested !== null && isset($tabs[$requested]) ? $requested : self::TODAY;
    }

    /**
     * Attention counts per module tab.
     *
     * @param array<string, array<string, mixed>> $tabs from tabsFor()
     *
     * @return array<string, int>
     */
    public function badges(int $userId, array $tabs): array
    {
        $out = [];

        foreach ($tabs as $key => $tab) {
            $out[$key] = $this->provider($tab['provider'])->badge($userId);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tab one entry from tabsFor()
     *
     * @return array<string, mixed>
     */
    public function data(int $userId, array $tab): array
    {
        return $this->provider($tab['provider'])->data($userId);
    }

    /**
     * Unread notifications for the Today tab.
     *
     * @return list<array<string, mixed>>
     */
    public function unreadNotifications(int $userId, int $limit = 8): array
    {
        return db_connect()->table('notifications')->where('user_id', $userId)->where('read_at', null)
            ->orderBy('created_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    private function provider(string $class): DashboardTab
    {
        $provider = new $class();

        if (! $provider instanceof DashboardTab) {
            throw new \LogicException($class . ' must implement ' . DashboardTab::class);
        }

        return $provider;
    }
}
