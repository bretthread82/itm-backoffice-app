<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Files\FileMigrator;
use App\Services\Files\LocalFileStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * One-off, when moving a running install to the shared (S3) file store. Safe to repeat.
 */
class FilesMigrateToStore extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'files:migrate-to-store';
    protected $description = 'Copies the files in writable/uploads to the file store set by filestore.driver (S3), checking each copy. Never deletes the local files.';
    protected $usage       = 'files:migrate-to-store [--dry-run]';
    protected $options     = ['--dry-run' => 'Show what would be copied without copying anything.'];

    public function run(array $params): void
    {
        $dry = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $to  = service('fileStore');

        if ($to instanceof LocalFileStore) {
            CLI::error('filestore.driver is "local", so there is nowhere to copy to. Set filestore.driver = s3 (and the bucket settings) in .env first.');

            return;
        }

        $r = (new FileMigrator(new LocalFileStore(), $to))->run($dry);

        CLI::write(($dry ? 'Dry run: ' : '') . "{$r['copied']} file(s) " . ($dry ? 'would be copied' : 'copied') . ", {$r['skipped']} already there.", 'green');
        foreach ($r['failed'] as $key) {
            CLI::write("FAILED: {$key}", 'red');
        }
        if ($r['failed'] !== []) {
            CLI::write('Some files were not copied. Fix the cause and run this command again before switching over.', 'red');
        }
    }
}
