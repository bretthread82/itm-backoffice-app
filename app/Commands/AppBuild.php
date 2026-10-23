<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AppBuild extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'app:build';
    protected $description = 'Stamps a new build (number, git commit, date, versions) into build.json. Run on every release or deploy.';
    protected $usage       = 'app:build';

    public function run(array $params)
    {
        $versions = service('versions');
        $stamp    = $versions->nextStamp();

        CLI::write('Build ' . $stamp['number'] . ' of platform v' . $stamp['platform']
            . ($stamp['commit'] ? ' at ' . $stamp['commit'] : '') . ($stamp['dirty'] ? ' (uncommitted changes)' : ''), 'green');

        foreach ($stamp['modules'] as $key => $version) {
            CLI::write(str_pad((string) $key, 12) . $version);
        }

        return EXIT_SUCCESS;
    }
}
