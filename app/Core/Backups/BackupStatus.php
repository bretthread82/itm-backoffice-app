<?php

declare(strict_types=1);

namespace App\Core\Backups;

use Config\Backups as BackupsConfig;

/**
 * What the status page and the staleness check need to know about backup health.
 */
class BackupStatus
{
    public function __construct(private readonly ?BackupsConfig $config = null)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastRun(): ?array
    {
        return db_connect()->table('bk_runs')->orderBy('id', 'DESC')->get(1)->getRowArray();
    }

    /**
     * @return array<string, mixed>|null  most recent run that produced a copy somewhere
     */
    public function lastGood(): ?array
    {
        return db_connect()->table('bk_runs')->whereIn('status', ['success', 'partial'])->orderBy('id', 'DESC')->get(1)->getRowArray();
    }

    public function isStale(): bool
    {
        $good = $this->lastGood();
        $cfg  = $this->config ?? config(BackupsConfig::class);

        if ($good === null) {
            return true;
        }

        return strtotime((string) $good['finished_at'] . ' UTC') < time() - $cfg->staleHours * 3600;
    }

    /**
     * @return array{total: int, enabled: int, healthy: int, failing: int}
     */
    public function destinationHealth(): array
    {
        $rows = (new DestinationService())->all();
        $out  = ['total' => count($rows), 'enabled' => 0, 'healthy' => 0, 'failing' => 0];

        foreach ($rows as $r) {
            if ((int) $r['enabled'] === 1) {
                $out['enabled']++;

                if ($r['last_test_ok'] !== null && (int) $r['last_test_ok'] === 0) {
                    $out['failing']++;
                } elseif ((int) $r['last_test_ok'] === 1) {
                    $out['healthy']++;
                }
            }
        }

        return $out;
    }

    /**
     * Tells the people who manage backups when no good backup exists within the allowed window,
     * at most once a day. Cron calls this, so it also catches a cron job that stopped running.
     */
    public function notifyIfStale(): bool
    {
        if (! $this->isStale()) {
            return false;
        }

        if (cache('backups_stale_notified')) {
            return false;
        }

        cache()->save('backups_stale_notified', 1, 86400);

        service('notifier')->sendToPermission(
            'backups.manage',
            'backups.stale',
            'No recent database backup',
            'No successful backup in the last ' . ($this->config ?? config(BackupsConfig::class))->staleHours . ' hours. Check that the cron job is running.',
            'admin/backups',
        );

        return true;
    }
}
