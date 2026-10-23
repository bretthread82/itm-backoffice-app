<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Copies every file from one store to another and checks each copy by hash. It never deletes anything from the
 * source: keep the local files until the new store has been in use for a while.
 */
final class FileMigrator
{
    public const PREFIXES = ['invoices', 'documents', 'logos', 'bulk', 'imports'];

    public function __construct(private FileStore $from, private FileStore $to)
    {
    }

    /**
     * @return array{copied: int, skipped: int, failed: list<string>}
     */
    public function run(bool $dryRun = false): array
    {
        $result = ['copied' => 0, 'skipped' => 0, 'failed' => []];

        foreach (self::PREFIXES as $prefix) {
            foreach ($this->from->keys($prefix) as $key) {
                $source = $this->from->localPath($key);
                if ($source === null) {
                    continue; // vanished while we were running
                }
                $hash = hash_file('sha256', $source);

                if ($this->to->exists($key) && $this->hashOf($this->to, $key) === $hash) {
                    $result['skipped']++;

                    continue;
                }
                if ($dryRun) {
                    $result['copied']++;

                    continue;
                }

                try {
                    $this->to->put($key, $source);
                } catch (\RuntimeException) {
                    $result['failed'][] = $key;

                    continue;
                }
                if ($this->hashOf($this->to, $key) !== $hash) {
                    $result['failed'][] = $key;

                    continue;
                }
                $result['copied']++;
            }
        }

        return $result;
    }

    private function hashOf(FileStore $store, string $key): ?string
    {
        $data = $store->read($key);

        return $data === null ? null : hash('sha256', $data);
    }
}
