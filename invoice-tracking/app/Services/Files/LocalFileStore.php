<?php

declare(strict_types=1);

namespace App\Services\Files;

use RuntimeException;

/** Files on this server's disk, under writable/uploads. The default, and right for a single server. */
final class LocalFileStore implements FileStore
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? WRITEPATH . 'uploads', '/\\');
    }

    public function put(string $key, string $localPath): void
    {
        $path = $this->path($key);
        $dir  = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('The upload folder is not writable.');
        }
        if (is_uploaded_file($localPath)) {
            if (! @move_uploaded_file($localPath, $path)) {
                throw new RuntimeException('The file could not be saved.');
            }

            return;
        }
        if (! @copy($localPath, $path)) { // not an HTTP upload (CLI, tests): the source is left to its owner
            throw new RuntimeException('The file could not be saved.');
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function size(string $key): ?int
    {
        $path = $this->path($key);

        return is_file($path) ? (int) filesize($path) : null;
    }

    public function read(string $key): ?string
    {
        $path = $this->path($key);
        if (! is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);

        return $data === false ? null : $data;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        return ! is_file($path) || @unlink($path);
    }

    public function localPath(string $key): ?string
    {
        $path = $this->path($key);

        return is_file($path) ? $path : null;
    }

    public function keys(string $prefix): array
    {
        $dir = $this->root . '/' . rtrim($prefix, '/');
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_file($dir . '/' . $name)) {
                $out[] = rtrim($prefix, '/') . '/' . $name;
            }
        }

        return $out;
    }

    private function path(string $key): string
    {
        return $this->root . '/' . FileKey::check($key);
    }
}
