<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\JobLock;
use App\Services\NotificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Retries emails that failed or were left pending (after an outage). Run every few minutes:
 *   *\/5 * * * *  cd /var/www/app && php spark notifications:send
 */
class NotificationsSend extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'notifications:send';
    protected $description = 'Sends pending and previously failed notification emails (up to 5 attempts each).';

    public function run(array $params): void
    {
        $lock = (new JobLock())->run('notifications:send', static fn (): array => (new NotificationService())->sendPending());
        if (! $lock['ran']) {
            CLI::write('Another notifications:send is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $result = $lock['result'];

        CLI::write("Sent {$result['sent']}, still failing {$result['failed']}.", $result['failed'] > 0 ? 'yellow' : 'green');
    }
}
