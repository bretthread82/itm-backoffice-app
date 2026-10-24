<?php

declare(strict_types=1);

namespace App\Core\Backups\Http;

/**
 * Minimal HTTP client for the backup destinations. It can send a slice of a file as the request body, so large
 * backups are streamed from disk and never held in memory. Tests replace it with a fake.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers  header name => value
     * @param array{path: string, offset: int, length: int}|null $upload  send this part of a file as the body
     *
     * @return array{status: int, headers: array<string, string>, body: string}  header names are lower case
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, ?array $upload = null): array;
}
