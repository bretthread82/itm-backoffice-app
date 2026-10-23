<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\SharePoint\SharePointClient;
use App\Services\SharePoint\SharePointException;
use App\Services\SharePoint\SharePointResult;

/**
 * SharePoint stand-in for tests. Records what it was asked and can be told to fail.
 */
final class FakeSharePointClient implements SharePointClient
{
    /** @var list<array{connection: array<string, mixed>, payload: array<string, mixed>, name: string, fileUrl: string|null, itemId: string|null, bytes: int}> */
    public array $stored = [];

    /** @var list<array<string, mixed>> */
    public array $tested = [];

    /** Failures to throw, one per call, in order. A null entry means "succeed this time". */
    public array $failures = [];

    /** @var array{site: string, library: string, list: string|null}|SharePointException */
    public $testResult;

    /** When set, the file step succeeds (and is reported) but the item step then fails once. */
    public bool $failAfterFile = false;

    public function __construct()
    {
        $this->testResult = ['site' => 'Test site', 'library' => 'Documents', 'list' => 'Completed Invoices'];
    }

    public function test(array $connection): array
    {
        $this->tested[] = $connection;
        if ($this->testResult instanceof SharePointException) {
            throw $this->testResult;
        }

        return $this->testResult;
    }

    /** @var list<array{name: string, mime: string, bytes: int}> */
    public array $storedFiles = [];

    /** Number of upcoming storeFile() calls that should fail. */
    public int $failFiles = 0;

    public function storeFile(array $connection, array $payload, string $path, string $name, string $mime): string
    {
        $this->storedFiles[] = ['name' => $name, 'mime' => $mime, 'bytes' => (int) @filesize($path)];
        if ($this->failFiles > 0) {
            $this->failFiles--;

            throw new SharePointException('Document upload failed');
        }

        return 'https://sp.test/files/' . $name;
    }

    public function store(array $connection, array $payload, string $pdfPath, string $pdfName, ?string $existingFileUrl, ?string $existingItemId, callable $progress): SharePointResult
    {
        $this->stored[] = [
            'connection' => $connection, 'payload' => $payload, 'name' => $pdfName, 'fileUrl' => $existingFileUrl, 'itemId' => $existingItemId, 'bytes' => (int) @filesize($pdfPath),
        ];

        $failure = array_shift($this->failures);
        if ($failure instanceof SharePointException && ! $this->failAfterFile) {
            throw $failure;
        }

        $fileUrl = $existingFileUrl ?: 'https://sp.test/files/' . $pdfName;
        if ($existingFileUrl === null || $existingFileUrl === '') {
            $progress('file', $fileUrl);
        }

        if ($this->failAfterFile) {
            $this->failAfterFile = false;

            throw new SharePointException('Item step failed');
        }

        $itemId = $existingItemId ?: 'item-' . count($this->stored);
        if ($existingItemId === null || $existingItemId === '') {
            $progress('item', $itemId);
        }

        return new SharePointResult($fileUrl, $itemId);
    }
}
