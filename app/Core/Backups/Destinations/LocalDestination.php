<?php

declare(strict_types=1);

namespace App\Core\Backups\Destinations;

use RuntimeException;

/**
 * A folder on the server or on a mounted share (NFS, SMB, a second disk).
 */
final class LocalDestination extends Destination
{
    private string $dir;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $dir = rtrim(trim((string) ($settings['path'] ?? '')), '/\\');

        if ($dir === '') {
            throw new RuntimeException('Enter the folder to copy backups to.');
        }

        $this->dir = $dir;
    }

    public function put(string $localPath, string $name): string
    {
        $name = self::validName($name);

        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0750, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('Cannot create the folder ' . $this->dir . '.');
        }

        $target = $this->dir . DIRECTORY_SEPARATOR . $name;
        $part   = $target . '.part';

        if (! @copy($localPath, $part) || ! @rename($part, $target)) {
            @unlink($part);

            throw new RuntimeException('Could not copy the backup to ' . $this->dir . ' (permissions or disk space).');
        }

        return $target;
    }

    public function delete(string $name): void
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . self::validName($name);

        if (is_file($file) && ! @unlink($file)) {
            throw new RuntimeException('Could not delete ' . $name . '.');
        }
    }

    public function list(): array
    {
        if (! is_dir($this->dir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($this->dir) ?: [],
            fn (string $f) => $f !== '.' && $f !== '..' && is_file($this->dir . DIRECTORY_SEPARATOR . $f),
        ));
    }
}
