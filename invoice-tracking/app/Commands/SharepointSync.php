<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\JobLock;
use App\Services\SharePoint\SyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sends completed invoices to SharePoint. Run every few minutes from cron:
 *   *\/5 * * * *  cd /var/www/app && php spark sharepoint:sync
 * Does nothing for companies without an enabled, complete SharePoint connection (their invoices wait).
 */
class SharepointSync extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'sharepoint:sync';
    protected $description = 'Sends completed invoices to SharePoint (retries failures with back-off).';
    protected $usage       = 'sharepoint:sync [limit]';
    protected $arguments   = ['limit' => 'Most invoices to process in this run (default 20)'];

    public function run(array $params): void
    {
        $limit = max(1, min(200, (int) ($params[0] ?? 20)));
        $lock  = (new JobLock())->run('sharepoint:sync', static fn (): array => (new SyncService())->run($limit));
        if (! $lock['ran']) {
            CLI::write('Another sharepoint:sync is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $r = $lock['result'];

        CLI::write("Processed {$r['processed']}: {$r['synced']} sent, {$r['retrying']} will retry, {$r['failed']} gave up, {$r['not_configured']} waiting for a connection, {$r['skipped']} skipped.", $r['failed'] > 0 ? 'yellow' : 'green');
    }
}
