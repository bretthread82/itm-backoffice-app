<?php

declare(strict_types=1);

namespace App\Core\Backups\Destinations;

use App\Core\Backups\Http\Transport;
use RuntimeException;

/**
 * Amazon S3 or any S3-compatible bucket. Small files go up in one request, large ones as a multipart upload.
 */
final class S3Destination extends Destination
{
    private const SINGLE_LIMIT = 67108864;  // 64 MiB
    private const PART_SIZE    = 33554432;  // 32 MiB

    private string $scheme;
    private string $host;
    private string $bucket;
    private string $region;
    private string $prefix;
    private bool $pathStyle;
    private string $accessKey;
    private string $secretKey;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings, private readonly Transport $http)
    {
        $this->bucket    = trim((string) ($settings['bucket'] ?? ''));
        $this->region    = trim((string) ($settings['region'] ?? '')) ?: 'us-east-1';
        $this->accessKey = trim((string) ($settings['access_key'] ?? ''));
        $this->secretKey = (string) ($settings['secret_key'] ?? '');
        $this->prefix    = self::cleanFolder((string) ($settings['prefix'] ?? ''));

        if ($this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
            throw new RuntimeException('Enter the bucket, access key and secret key.');
        }

        $endpoint = trim((string) ($settings['endpoint'] ?? ''));

        if ($endpoint === '') {
            $endpoint = 'https://s3.' . $this->region . '.amazonaws.com';
        } elseif (! preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        $parts = parse_url(rtrim($endpoint, '/'));

        if (! $parts || empty($parts['host'])) {
            throw new RuntimeException('The endpoint address is not valid.');
        }

        $this->scheme    = strtolower($parts['scheme'] ?? 'https');
        $this->host      = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->pathStyle = ! empty($settings['path_style']);
    }

    public function put(string $localPath, string $name): string
    {
        $key  = $this->key(self::validName($name));
        $size = (int) filesize($localPath);

        if ($size <= self::SINGLE_LIMIT) {
            $res = $this->request('PUT', $key, [], $this->hashRange($localPath, 0, $size), null, ['path' => $localPath, 'offset' => 0, 'length' => $size]);
            $this->expect($res, [200], 'Upload');
        } else {
            $this->multipart($localPath, $key, $size);
        }

        return $this->bucket . '/' . $key;
    }

    public function delete(string $name): void
    {
        $res = $this->request('DELETE', $this->key(self::validName($name)), [], hash('sha256', ''));
        $this->expect($res, [200, 204, 404], 'Delete');
    }

    public function list(): array
    {
        $names = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'prefix' => $this->prefix !== '' ? $this->prefix . '/' : ''];

            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $res = $this->request('GET', '', $query, hash('sha256', ''));
            $this->expect($res, [200], 'Listing');

            $xml = $this->xml($res['body']);

            foreach ($xml->Contents ?? [] as $item) {
                $leaf = basename((string) $item->Key);

                if ($leaf !== '') {
                    $names[] = $leaf;
                }
            }

            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        return $names;
    }

    private function multipart(string $localPath, string $key, int $size): void
    {
        $res = $this->request('POST', $key, ['uploads' => ''], hash('sha256', ''));
        $this->expect($res, [200], 'Starting the upload');

        $uploadId = (string) $this->xml($res['body'])->UploadId;

        if ($uploadId === '') {
            throw new RuntimeException('The store did not start a multipart upload.');
        }

        try {
            $etags = [];
            $total = (int) ceil($size / self::PART_SIZE);

            for ($n = 1; $n <= $total; $n++) {
                $offset = ($n - 1) * self::PART_SIZE;
                $length = min(self::PART_SIZE, $size - $offset);
                $part   = $this->request('PUT', $key, ['partNumber' => (string) $n, 'uploadId' => $uploadId], $this->hashRange($localPath, $offset, $length), null, ['path' => $localPath, 'offset' => $offset, 'length' => $length]);
                $this->expect($part, [200], 'Uploading part ' . $n);

                $etags[$n] = $part['headers']['etag'] ?? '';
            }

            $body = '<CompleteMultipartUpload>';

            foreach ($etags as $n => $etag) {
                $body .= '<Part><PartNumber>' . $n . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
            }

            $body .= '</CompleteMultipartUpload>';

            $done = $this->request('POST', $key, ['uploadId' => $uploadId], hash('sha256', $body), $body);
            $this->expect($done, [200], 'Finishing the upload');

            // S3 can answer 200 with an error document.
            if (str_contains($done['body'], '<Error>')) {
                throw new RuntimeException('The store could not finish the upload.');
            }
        } catch (\Throwable $e) {
            try {
                $this->request('DELETE', $key, ['uploadId' => $uploadId], hash('sha256', ''));
            } catch (\Throwable) {
                // The original error is the useful one.
            }

            throw $e;
        }
    }

    /**
     * @param array<string, string>                                    $query
     * @param array{path: string, offset: int, length: int}|null       $upload
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $key, array $query, string $payloadHash, ?string $body = null, ?array $upload = null): array
    {
        $uri = $this->pathStyle
            ? '/' . rawurlencode($this->bucket) . ($key !== '' ? '/' . $this->encodeKey($key) : '/')
            : ($key !== '' ? '/' . $this->encodeKey($key) : '/');
        $host = $this->pathStyle ? $this->host : $this->bucket . '.' . $this->host;
        $date = gmdate('Ymd\THis\Z');

        $signed = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $date];
        $auth   = S3Signer::authorization($method, $uri, $query, $signed, $payloadHash, $this->accessKey, $this->secretKey, $this->region, $date);

        $url = $this->scheme . '://' . $host . $uri . ($query ? '?' . S3Signer::canonicalQuery($query) : '');

        return $this->http->send($method, $url, [
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $date,
            'Authorization'        => $auth,
        ], $body, $upload);
    }

    private function key(string $name): string
    {
        return ($this->prefix !== '' ? $this->prefix . '/' : '') . $name;
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function hashRange(string $path, int $offset, int $length): string
    {
        $h  = fopen($path, 'rb');
        $ctx = hash_init('sha256');

        if (! $h) {
            throw new RuntimeException('Cannot read ' . $path . '.');
        }

        fseek($h, $offset);

        while ($length > 0 && ! feof($h)) {
            $chunk = (string) fread($h, min(1048576, $length));

            if ($chunk === '') {
                break;
            }

            hash_update($ctx, $chunk);
            $length -= strlen($chunk);
        }

        fclose($h);

        return hash_final($ctx);
    }

    private function xml(string $body): \SimpleXMLElement
    {
        // Namespaces vary between stores; the element names are what matter.
        $xml = @simplexml_load_string((string) preg_replace('/\sxmlns="[^"]*"/', '', $body));

        if ($xml === false) {
            throw new RuntimeException('The store sent a reply that could not be read.');
        }

        return $xml;
    }

    /**
     * @param array{status: int, body: string} $res
     * @param list<int>                        $ok
     */
    private function expect(array $res, array $ok, string $what): void
    {
        if (in_array($res['status'], $ok, true)) {
            return;
        }

        $code = '';

        if (preg_match('#<Code>([^<]+)</Code>#', $res['body'], $m)) {
            $code = $m[1];
        }

        $hint = match ($code) {
            'InvalidAccessKeyId', 'SignatureDoesNotMatch' => ' Check the access key, secret key and region.',
            'NoSuchBucket'                                => ' The bucket was not found.',
            'AccessDenied'                                => ' The key is not allowed to do this.',
            default                                       => '',
        };

        throw new RuntimeException($what . ' failed (HTTP ' . $res['status'] . ($code !== '' ? ', ' . $code : '') . ').' . $hint);
    }
}
