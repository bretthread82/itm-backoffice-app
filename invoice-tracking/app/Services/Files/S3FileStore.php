<?php

declare(strict_types=1);

namespace App\Services\Files;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use RuntimeException;

/**
 * Files in an S3 bucket (or any S3-compatible service such as MinIO). Every server shares the same bucket, so a file
 * uploaded through one server is available on all of them. Objects are private; they are only ever served through the
 * application after an access check. Nothing here is encrypted by us: turn on server-side encryption on the bucket.
 */
final class S3FileStore implements FileStore
{
    /** @var list<string> temporary copies made by localPath(), removed at the end of the request */
    private array $temps = [];

    public function __construct(private S3Client $client, private string $bucket, private string $prefix = '')
    {
        $this->prefix = $prefix === '' ? '' : trim($prefix, '/') . '/';
        register_shutdown_function(function (): void {
            foreach ($this->temps as $tmp) {
                @unlink($tmp);
            }
        });
    }

    public function put(string $key, string $localPath): void
    {
        try {
            $this->client->putObject([
                'Bucket'     => $this->bucket,
                'Key'        => $this->objectKey($key),
                'SourceFile' => $localPath,
            ]);
        } catch (AwsException $e) {
            log_message('error', 'File store upload failed: ' . $e->getAwsErrorCode());

            throw new RuntimeException('The file could not be saved.', 0, $e);
        }
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExistV2($this->bucket, $this->objectKey($key));
    }

    public function size(string $key): ?int
    {
        try {
            return (int) $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->objectKey($key)])['ContentLength'];
        } catch (AwsException $e) {
            return $this->missing($e) ? null : throw new RuntimeException('The file store could not be reached.', 0, $e);
        }
    }

    public function read(string $key): ?string
    {
        try {
            return (string) $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->objectKey($key)])['Body'];
        } catch (AwsException $e) {
            return $this->missing($e) ? null : throw new RuntimeException('The file store could not be reached.', 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->objectKey($key)]); // succeeds when absent

            return true;
        } catch (AwsException $e) {
            log_message('error', 'File store delete failed: ' . $e->getAwsErrorCode());

            return false;
        }
    }

    public function localPath(string $key): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fs_');
        if ($tmp === false) {
            throw new RuntimeException('No temporary space available.');
        }
        // Keep the extension: importers decide between CSV and XLSX by it.
        $withExt = $tmp . '.' . pathinfo($key, PATHINFO_EXTENSION);
        @rename($tmp, $withExt);
        $this->temps[] = $withExt;

        try {
            $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->objectKey($key), 'SaveAs' => $withExt]);
        } catch (AwsException $e) {
            @unlink($withExt);

            return $this->missing($e) ? null : throw new RuntimeException('The file store could not be reached.', 0, $e);
        }

        return $withExt;
    }

    public function keys(string $prefix): array
    {
        $out = [];
        $p   = $this->prefix . rtrim($prefix, '/') . '/';
        foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $p]) as $page) {
            foreach ($page['Contents'] ?? [] as $obj) {
                $out[] = substr((string) $obj['Key'], strlen($this->prefix));
            }
        }

        return $out;
    }

    private function objectKey(string $key): string
    {
        return $this->prefix . FileKey::check($key);
    }

    private function missing(AwsException $e): bool
    {
        return in_array($e->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true) || $e->getStatusCode() === 404;
    }
}
