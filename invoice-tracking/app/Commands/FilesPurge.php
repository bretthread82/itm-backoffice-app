<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\FilePurger;
use App\Services\JobLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily:  15 3 * * *  cd /var/www/app && php spark files:purge
 */
class FilesPurge extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'files:purge';
    protected $description = 'Deletes server copies of invoice PDFs after the retention period (only once SharePoint has confirmed receipt).';
    protected $usage       = 'files:purge [--dry-run]';
    protected $options     = ['--dry-run' => 'Show what would be deleted without deleting anything.'];

    public function run(array $params): void
    {
        $dry = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $lock = (new JobLock())->run('files:purge', static fn (): array => (new FilePurger())->run($dry));
        if (! $lock['ran']) {
            CLI::write('Another files:purge is already running (on this or another server). Skipped.', 'yellow');

            return;
        }
        $r = $lock['result'];

        $mb = number_format($r['bytes'] / 1048576, 1);
        if ($dry) {
            CLI::write("Dry run: {$r['eligible']} file(s) ({$mb} MB) would be deleted. Nothing was changed.", 'yellow');

            return;
        }
        CLI::write("Deleted {$r['deleted']} file(s) ({$mb} MB), {$r['missing']} already missing, {$r['failed']} could not be deleted.", $r['failed'] > 0 ? 'red' : 'green');
    }
}
