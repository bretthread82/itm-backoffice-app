<?php

declare(strict_types=1);

namespace App\Core\Backups\Destinations;

/**
 * AWS Signature Version 4 for S3 and S3-compatible stores (MinIO, Wasabi, Backblaze B2, Cloudflare R2, Ceph...).
 */
final class S3Signer
{
    /**
     * Builds the Authorization header value.
     *
     * @param string               $canonicalUri  the request path, already percent-encoded
     * @param array<string,string> $query         unencoded query parameters
     * @param array<string,string> $headers       every header to sign (must include host and x-amz-date), lower-case names
     */
    public static function authorization(
        string $method,
        string $canonicalUri,
        array $query,
        array $headers,
        string $payloadHash,
        string $accessKey,
        string $secretKey,
        string $region,
        string $amzDate,
    ): string {
        ksort($headers);

        $canonicalHeaders = '';

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) preg_replace('/\s+/', ' ', $value)) . "\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $request       = implode("\n", [$method, $canonicalUri, self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);

        $day   = substr($amzDate, 0, 8);
        $scope = $day . '/' . $region . '/s3/aws4_request';
        $sts   = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $request)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $secretKey, true), true), true), true);

        return 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ',SignedHeaders=' . $signedHeaders . ',Signature=' . hash_hmac('sha256', $sts, $key);
    }

    /**
     * @param array<string,string> $query
     */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];

        foreach ($query as $k => $v) {
            $pairs[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }

        ksort($pairs);

        $out = [];

        foreach ($pairs as $k => $v) {
            $out[] = $k . '=' . $v;
        }

        return implode('&', $out);
    }
}
