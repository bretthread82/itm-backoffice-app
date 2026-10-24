<?php

declare(strict_types=1);

namespace App\Core\Backups\Http;

use RuntimeException;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null, ?array $upload = null): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is needed to send backups to a remote destination.');
        }

        $responseHeaders = [];
        $lines           = ['Expect:'];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $ch   = curl_init($url);
        $file = null;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 7200,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($upload !== null) {
            $file = fopen($upload['path'], 'rb');

            if (! $file) {
                throw new RuntimeException('Cannot read ' . $upload['path'] . '.');
            }

            fseek($file, $upload['offset']);
            $left = $upload['length'];

            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILESIZE, $upload['length']);
            curl_setopt($ch, CURLOPT_READFUNCTION, static function ($c, $fh, int $max) use ($file, &$left): string {
                if ($left <= 0) {
                    return '';
                }

                $data = (string) fread($file, min($max, $left));
                $left -= strlen($data);

                return $data;
            });
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_resource($file)) {
            fclose($file);
        }

        if ($result === false) {
            throw new RuntimeException('Connection failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result];
    }
}
