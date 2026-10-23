<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Analytics\AnalyticsLoader;
use App\Services\Analytics\BacklogSnapshotter;
use App\Services\JobLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly:  30 1 * * *  cd /var/www/app && php spark analytics:load
 */
class AnalyticsLoad extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'analytics:load';
    protected $description = 'Copies new invoice activity into the analytics database and records today\'s backlog snapshot.';
    protected $usage       = 'analytics:load [--dry-run]';
    protected $options     = ['--dry-run' => 'Show how many invoices would be loaded without writing anything.'];

    public function run(array $params): void
    {
        $dry  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $lock = (new JobLock())->run('analytics:load', static function () use ($dry): array {
            $r = (new AnalyticsLoader())->run($dry);
            $r['snapshot'] = $dry ? 0 : (new BacklogSnapshotter())->snapshot();

            return $r;
        });
        if (! $lock['ran']) {
            CLI::write('Another analytics:load is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $r = $lock['result'];

        if ($dry) {
            CLI::write("Dry run: {$r['touched']} invoice(s) would be loaded. Nothing was changed.", 'yellow');

            return;
        }
        CLI::write("Loaded {$r['rebuilt']} invoice(s), removed {$r['removed']} deleted one(s), wrote {$r['snapshot']} backlog snapshot row(s).", 'green');
    }
}
