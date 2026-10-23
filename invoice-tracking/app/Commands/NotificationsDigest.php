<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\DigestService;
use App\Services\JobLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily summary of outstanding invoices. Run once each morning from cron:
 *   30 7 * * *  cd /var/www/app && php spark notifications:digest
 * Safe to run more than once a day: each user is only sent one.
 */
class NotificationsDigest extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'notifications:digest';
    protected $description = 'Sends each user their daily summary of outstanding invoices (bell + email).';

    public function run(array $params): void
    {
        $lock = (new JobLock())->run('notifications:digest', static fn (): array => (new DigestService())->run());
        if (! $lock['ran']) {
            CLI::write('Another notifications:digest is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $result = $lock['result'];

        CLI::write("Checked {$result['users']} users, sent {$result['sent']} summaries.", 'green');
    }
}
