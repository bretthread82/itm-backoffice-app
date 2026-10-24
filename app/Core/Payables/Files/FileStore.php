<?php

declare(strict_types=1);

namespace App\Core\Payables\Files;

/**
 * Where uploaded files live. Keys look like "invoices/<name>.pdf", "logos/<name>.png", "bulk/<token>.csv",
 * "imports/<name>.xlsx". The local driver keeps them under writable/uploads (single server); the S3 driver
 * keeps them in a bucket every server can reach.
 */
interface FileStore
{
    /** Copies a local file into the store under $key (replacing any file already there). */
    public function put(string $key, string $localPath): void;

    public function exists(string $key): bool;

    public function size(string $key): ?int;

    /** File contents, or null when the key does not exist. */
    public function read(string $key): ?string;

    /** True when the file is gone afterwards (including when it was never there). */
    public function delete(string $key): bool;

    /**
     * A readable local file with the key's contents, or null when the key does not exist. The local driver returns
     * the real file; other drivers return a temporary copy that is removed when the request ends. Callers must
     * treat it as read-only and never delete it.
     */
    public function localPath(string $key): ?string;

    /**
     * @return list<string> keys under a prefix such as "invoices/"
     */
    public function keys(string $prefix): array;
}
