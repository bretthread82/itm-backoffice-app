<?php

namespace App\Commands;

use App\Core\VersionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Backoffice;

class VersionBump extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'version:bump';
    protected $description = 'Raises the major, minor or bug number of a module (or the platform) in Config\Backoffice.';
    protected $usage       = 'version:bump <module|platform> <major|minor|bug>';
    protected $arguments   = [
        'module|platform' => 'A module key from Config\Backoffice, or "platform".',
        'major|minor|bug' => 'major = breaking change, minor = new feature, bug = fix.',
    ];

    public function run(array $params)
    {
        $target = $params[0] ?? null;
        $part   = $params[1] ?? null;

        if ($target === null || $part === null) {
            CLI::error('Usage: php spark ' . $this->usage);

            return EXIT_ERROR;
        }

        $config = config(Backoffice::class);

        if ($target !== 'platform' && ! isset($config->modules[$target])) {
            CLI::error('Unknown module "' . $target . '". Known: platform, ' . implode(', ', array_keys($config->modules)) . '.');

            return EXIT_ERROR;
        }

        $current = $target === 'platform' ? $config->platformVersion : (string) ($config->modules[$target]['version'] ?? '');

        try {
            $next = VersionService::bump($current, $part);
        } catch (\InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $file = APPPATH . 'Config/Backoffice.php';
        $src  = (string) file_get_contents($file);

        $pattern = $target === 'platform'
            ? '/(public string \$platformVersion = \')\d+\.\d+\.\d+(\')/'
            : "/(\n        '" . preg_quote($target, '/') . "' => \[\s*\n\s*'version'\s*=>\s*')\d+\.\d+\.\d+(')/";

        $new = preg_replace($pattern, '${1}' . $next . '${2}', $src, 1, $count);

        if ($count !== 1 || $new === null) {
            CLI::error('Could not find the version line for "' . $target . '" in Config/Backoffice.php.');

            return EXIT_ERROR;
        }

        file_put_contents($file, $new);

        CLI::write($target . ': ' . $current . ' -> ' . $next, 'green');
        CLI::write('Now add a dated entry to docs/EVOLUTION.md saying what changed.', 'yellow');

        return EXIT_SUCCESS;
    }
}
