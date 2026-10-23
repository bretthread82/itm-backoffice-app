<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Analytics\AnalyticsMigrator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Run after `php spark migrate --all` on install and on every upgrade.
 */
class AnalyticsMigrate extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'analytics:migrate';
    protected $description = 'Creates or updates the tables in the separate analytics database (database.analytics.* in .env).';
    protected $usage       = 'analytics:migrate';

    public function run(array $params): void
    {
        $db = db_connect(AnalyticsMigrator::GROUP);
        CLI::write('Analytics database: ' . $db->getDatabase(), 'yellow');

        foreach ((new AnalyticsMigrator())->upgrade() as $line) {
            CLI::write($line);
        }
        CLI::write('Analytics schema is up to date.', 'green');
    }
}
