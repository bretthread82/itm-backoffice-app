<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

/**
 * Talks to SharePoint for one connection. Behind an interface so everything that uses it is tested
 * without a network, and so the real implementation (Microsoft Graph) can wait for credentials.
 *
 * $connection is the array from Connections::forClient() (secret decrypted).
 */
interface SharePointClient
{
    /**
     * Checks the credentials, the site, the document library, the list and that every mapped column
     * exists. Changes nothing in SharePoint.
     *
     * @param array<string, mixed> $connection
     *
     * @return array{site: string, library: string, list: string|null}
     *
     * @throws SharePointException
     */
    public function test(array $connection): array;

    /**
     * Stores the PDF in the library and creates the list item. Safe to call again after a failure:
     * pass what an earlier attempt already achieved and those steps are skipped.
     *
     * @param array<string, mixed>        $connection
     * @param array<string, mixed>        $payload     invoice values keyed by Connections::MAPPING_KEYS
     * @param callable(string, string): void $progress stage ("file" or "item") and its value, called as soon as each step succeeds
     *
     * @throws SharePointException
     */
    public function store(array $connection, array $payload, string $pdfPath, string $pdfName, ?string $existingFileUrl, ?string $existingItemId, callable $progress): SharePointResult;

    /**
     * Stores a supporting document in the same library folder as the invoice PDF (no list item). Replaces a
     * file of the same name, so repeating it after a failure is safe.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload    the invoice's values (used to choose the folder)
     *
     * @return string the address of the stored file
     *
     * @throws SharePointException
     */
    public function storeFile(array $connection, array $payload, string $path, string $name, string $mime): string;
}
