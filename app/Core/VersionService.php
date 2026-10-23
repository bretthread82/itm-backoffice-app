<?php

namespace App\Core;

use Config\Backoffice;

/**
 * Versions and build stamp. The platform and every module carry a major.minor.bug version declared
 * in Config\Backoffice (bump with `php spark version:bump`). The build stamp (number, commit, date)
 * is written to build.json by `php spark app:build`; without it the commit is read from git.
 */
class VersionService
{
    public const PATTERN = '/^\d+\.\d+\.\d+$/';

    public const PARTS = ['major', 'minor', 'bug'];

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }

    /**
     * Next version for a bump: major resets minor and bug, minor resets bug.
     */
    public static function bump(string $version, string $part): string
    {
        if (! self::isValid($version) || ! in_array($part, self::PARTS, true)) {
            throw new \InvalidArgumentException('Use a major.minor.bug version and one of: ' . implode(', ', self::PARTS) . '.');
        }

        [$major, $minor, $bug] = array_map('intval', explode('.', $version));

        return match ($part) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            default => $major . '.' . $minor . '.' . ($bug + 1),
        };
    }

    public function platform(): string
    {
        return config(Backoffice::class)->platformVersion;
    }

    public function module(string $key): ?string
    {
        return config(Backoffice::class)->modules[$key]['version'] ?? null;
    }

    /**
     * @return array<string, string> module key => version
     */
    public function modules(): array
    {
        $out = [];

        foreach (config(Backoffice::class)->modules as $key => $module) {
            $out[(string) $key] = (string) ($module['version'] ?? '');
        }

        return $out;
    }

    /**
     * @return array{number: ?int, commit: ?string, built_at: ?string, label: string}
     */
    public function build(): array
    {
        $stamp = $this->stamp();

        if ($stamp === null) {
            $commit = $this->gitCommit();

            return ['number' => null, 'commit' => $commit, 'built_at' => null, 'label' => $commit !== null ? 'dev ' . $commit : 'dev'];
        }

        $number = isset($stamp['number']) ? (int) $stamp['number'] : null;
        $commit = isset($stamp['commit']) ? (string) $stamp['commit'] : null;

        return [
            'number'   => $number,
            'commit'   => $commit,
            'built_at' => isset($stamp['built_at']) ? (string) $stamp['built_at'] : null,
            'label'    => trim(($number !== null ? 'build ' . $number : '') . ($commit !== null ? ' (' . $commit . ')' : '')),
        ];
    }

    /**
     * Short text for the sidebar footer, e.g. "v1.0.0 · build 12 (a1b2c3d)".
     */
    public function label(): string
    {
        return 'v' . $this->platform() . ' · ' . $this->build()['label'];
    }

    /**
     * Writes the next build stamp and returns it.
     *
     * @return array<string, mixed>
     */
    public function nextStamp(): array
    {
        $previous = $this->stamp();
        $stamp    = [
            'number'   => (int) ($previous['number'] ?? 0) + 1,
            'commit'   => $this->gitCommit(),
            'dirty'    => $this->gitDirty(),
            'built_at' => date('c'),
            'platform' => $this->platform(),
            'modules'  => $this->modules(),
        ];

        file_put_contents($this->path(), json_encode($stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

        return $stamp;
    }

    public function path(): string
    {
        return ROOTPATH . 'build.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stamp(): ?array
    {
        $file = $this->path();

        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function gitCommit(): ?string
    {
        $out = $this->git('rev-parse --short HEAD');

        return $out !== '' ? $out : null;
    }

    private function gitDirty(): bool
    {
        return $this->git('status --porcelain') !== '';
    }

    private function git(string $args): string
    {
        if (! function_exists('exec')) {
            return '';
        }

        $lines = [];
        $code  = 0;
        @exec('git -C ' . escapeshellarg(rtrim(ROOTPATH, '/\\')) . ' ' . $args . ' 2>&1', $lines, $code);

        return $code === 0 ? trim(implode("\n", $lines)) : '';
    }
}
