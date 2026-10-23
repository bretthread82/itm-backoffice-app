<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Analytics\AnalyticsLoader;
use App\Services\Analytics\BacklogSnapshotter;
use App\Services\JobLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Run once after go-live (and again to repair the analytics data after a fix):  php spark analytics:backfill
 */
class AnalyticsBackfill extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'analytics:backfill';
    protected $description = 'Rebuilds every invoice in the analytics database from the full history and reconstructs past backlog snapshots.';
    protected $usage       = 'analytics:backfill [--dry-run]';
    protected $options     = ['--dry-run' => 'Show how many invoices would be rebuilt without writing anything.'];

    public function run(array $params): void
    {
        $dry  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        CLI::write('Analytics database: ' . db_connect('analytics')->getDatabase(), 'yellow');

        $lock = (new JobLock())->run('analytics:load', static function () use ($dry): array {
            $r = (new AnalyticsLoader())->rebuildAll($dry);
            if (! $dry) {
                $snaps = new BacklogSnapshotter();
                $r['estimated'] = $snaps->reconstruct();
                $r['snapshot']  = $snaps->snapshot();
            }

            return $r;
        });
        if (! $lock['ran']) {
            CLI::write('An analytics load is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $r = $lock['result'];

        if ($dry) {
            CLI::write("Dry run: {$r['touched']} invoice(s) would be rebuilt. Nothing was changed.", 'yellow');

            return;
        }
        CLI::write("Rebuilt {$r['rebuilt']} invoice(s), removed {$r['removed']} deleted one(s); wrote {$r['estimated']} estimated and {$r['snapshot']} current backlog row(s).", 'green');
    }
}
