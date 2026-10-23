<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\AppVersion;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * Run when cutting a release or a build, then commit version.json.
 */
class VersionBump extends BaseCommand
{
    protected $group       = 'Invoicing';
    protected $name        = 'version:bump';
    protected $description = 'Raises the version (major.minor.bugfix) or just the build number in version.json. Every bump adds one to the build number.';
    protected $usage       = 'version:bump <major|minor|bugfix|build>';
    protected $arguments   = ['part' => 'major (breaking change), minor (new feature), bugfix (fix only) or build (same version, new build).'];

    public function run(array $params): void
    {
        $part = $params[0] ?? null;

        if ($part === null) {
            CLI::write('Current version: ' . AppVersion::load()->full());

            return;
        }

        try {
            $next = AppVersion::load()->bump($part);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return;
        }

        $next->save();
        CLI::write('Version is now ' . $next->full(), 'green');
    }
}
