<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\EscalationService;
use App\Services\JobLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Reminds approvers, then escalates to group-level users, when an invoice sits too long at a step
 * that has an SLA. Run every hour from cron:
 *   0 * * * *  cd /var/www/app && php spark notifications:escalate
 * Safe to run as often as you like: each reminder/escalation is sent once per visit to a step.
 */
class NotificationsEscalate extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'notifications:escalate';
    protected $description = 'Sends reminders and escalations for invoices past a step\'s SLA (bell + email).';

    public function run(array $params): void
    {
        $lock = (new JobLock())->run('notifications:escalate', static fn (): array => (new EscalationService())->run());
        if (! $lock['ran']) {
            CLI::write('Another notifications:escalate is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $r = $lock['result'];

        CLI::write("Checked {$r['checked']} invoices with an SLA, sent {$r['reminders']} reminders and {$r['escalations']} escalations.", 'green');
    }
}
