<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * The application version (major.minor.bugfix) and build number, kept in version.json at the project root so it ships
 * with the code. Change it with `php spark version:bump`, not by hand.
 */
final class AppVersion
{
    public function __construct(public readonly int $major, public readonly int $minor, public readonly int $bugfix, public readonly int $build)
    {
    }

    public static function path(): string
    {
        return ROOTPATH . 'version.json';
    }

    /** Reads the file; an unreadable or malformed file gives 0.0.0 build 0 rather than breaking every page. */
    public static function load(?string $path = null): self
    {
        $data = is_file($path ??= self::path()) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($data) && preg_match('/^(\d+)\.(\d+)\.(\d+)$/', (string) ($data['version'] ?? ''), $m) === 1) {
            return new self((int) $m[1], (int) $m[2], (int) $m[3], max(0, (int) ($data['build'] ?? 0)));
        }

        return new self(0, 0, 0, 0);
    }

    public function version(): string
    {
        return "{$this->major}.{$this->minor}.{$this->bugfix}";
    }

    /** e.g. "1.2.3 (build 45)" */
    public function full(): string
    {
        return "{$this->version()} (build {$this->build})";
    }

    /**
     * $part is major, minor, bugfix or build. A bump of major/minor/bugfix zeroes the lower parts; every bump,
     * including "build", adds one to the build number, which never goes back.
     */
    public function bump(string $part): self
    {
        return match ($part) {
            'major'  => new self($this->major + 1, 0, 0, $this->build + 1),
            'minor'  => new self($this->major, $this->minor + 1, 0, $this->build + 1),
            'bugfix' => new self($this->major, $this->minor, $this->bugfix + 1, $this->build + 1),
            'build'  => new self($this->major, $this->minor, $this->bugfix, $this->build + 1),
            default  => throw new InvalidArgumentException('Part must be major, minor, bugfix or build.'),
        };
    }

    public function save(?string $path = null): void
    {
        $json = json_encode(['version' => $this->version(), 'build' => $this->build], JSON_PRETTY_PRINT) . "\n";
        file_put_contents($path ?? self::path(), $json);
    }
}
