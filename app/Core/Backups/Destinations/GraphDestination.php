<?php

declare(strict_types=1);

namespace App\Core\Backups\Destinations;

use App\Core\Backups\Http\Transport;
use RuntimeException;

/**
 * SharePoint document library or OneDrive, through Microsoft Graph with its own app registration
 * (application permission Files.ReadWrite.All, or Sites.Selected for a single site).
 * Files up to 4 MB go up in one request; larger ones use a resumable upload session.
 */
final class GraphDestination extends Destination
{
    private const GRAPH      = 'https://graph.microsoft.com/v1.0';
    private const LOGIN      = 'https://login.microsoftonline.com';
    private const SIMPLE_MAX = 4194304;     // 4 MiB
    private const RANGE_SIZE = 10485760;    // 10 MiB, a multiple of 320 KiB as Graph requires

    private string $tenant;
    private string $clientId;
    private string $secret;
    private string $driveBase;
    private string $folder;
    private ?string $token = null;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings, private readonly Transport $http)
    {
        $this->tenant   = trim((string) ($settings['tenant_id'] ?? ''));
        $this->clientId = trim((string) ($settings['client_id'] ?? ''));
        $this->secret   = (string) ($settings['client_secret'] ?? '');
        $this->folder   = self::cleanFolder((string) ($settings['folder'] ?? 'ITM Backups'));

        if ($this->tenant === '' || $this->clientId === '' || $this->secret === '') {
            throw new RuntimeException('Enter the tenant id, client id and client secret.');
        }

        $target = trim((string) ($settings['target'] ?? ''));

        if ($target === '') {
            throw new RuntimeException('Enter the SharePoint site or OneDrive user to save to.');
        }

        $this->driveBase = match ((string) ($settings['target_kind'] ?? 'site')) {
            'user'  => '/users/' . rawurlencode($target) . '/drive',
            'drive' => '/drives/' . rawurlencode($target),
            // "contoso.sharepoint.com:/sites/Finance" or a site id.
            default => '/sites/' . str_replace(['%3A', '%2C'], [':', ','], implode('/', array_map('rawurlencode', explode('/', $target)))) . (str_contains($target, ':') ? ':' : '') . '/drive',
        };
    }

    public function put(string $localPath, string $name): string
    {
        $name = self::validName($name);
        $size = (int) filesize($localPath);
        $path = $this->itemPath($name);

        if ($size <= self::SIMPLE_MAX) {
            $res = $this->call('PUT', $path . ':/content', ['Content-Type' => 'application/octet-stream'], null, ['path' => $localPath, 'offset' => 0, 'length' => $size]);
            $this->expect($res, [200, 201], 'Upload');
        } else {
            $this->session($localPath, $path, $size);
        }

        return $this->folder . '/' . $name;
    }

    public function delete(string $name): void
    {
        $res = $this->call('DELETE', $this->itemPath(self::validName($name)));
        $this->expect($res, [200, 204, 404], 'Delete');
    }

    public function list(): array
    {
        $names = [];
        $url   = $this->driveBase . '/root:/' . $this->encodePath($this->folder) . ':/children?$select=name&$top=200';

        while ($url !== null) {
            $res = $this->call('GET', $url);

            if ($res['status'] === 404) {
                return [];
            }

            $this->expect($res, [200], 'Listing');
            $data = json_decode($res['body'], true) ?: [];

            foreach ($data['value'] ?? [] as $item) {
                $names[] = (string) ($item['name'] ?? '');
            }

            $next = $data['@odata.nextLink'] ?? null;
            $url  = is_string($next) ? $next : null;
        }

        return array_values(array_filter($names));
    }

    private function session(string $localPath, string $path, int $size): void
    {
        $res = $this->call('POST', $path . ':/createUploadSession', ['Content-Type' => 'application/json'], '{"item":{"@microsoft.graph.conflictBehavior":"replace"}}');
        $this->expect($res, [200], 'Starting the upload');

        $uploadUrl = json_decode($res['body'], true)['uploadUrl'] ?? null;

        if (! is_string($uploadUrl) || ! str_starts_with($uploadUrl, 'https://')) {
            throw new RuntimeException('Microsoft did not start an upload session.');
        }

        for ($offset = 0; $offset < $size; $offset += self::RANGE_SIZE) {
            $length = min(self::RANGE_SIZE, $size - $offset);
            $range  = $this->http->send('PUT', $uploadUrl, [
                'Content-Length' => (string) $length,
                'Content-Range'  => 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size,
            ], null, ['path' => $localPath, 'offset' => $offset, 'length' => $length]);

            // 202 while more is expected, 200/201 when the last range lands.
            if (! in_array($range['status'], [200, 201, 202], true)) {
                $this->http->send('DELETE', $uploadUrl);

                throw new RuntimeException('Upload failed part-way (HTTP ' . $range['status'] . ').');
            }
        }
    }

    private function itemPath(string $name): string
    {
        return $this->driveBase . '/root:/' . $this->encodePath($this->folder . '/' . $name);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', $path), static fn ($p) => $p !== '')));
    }

    /**
     * @param array<string, string>                                $headers
     * @param array{path: string, offset: int, length: int}|null   $upload
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function call(string $method, string $pathOrUrl, array $headers = [], ?string $body = null, ?array $upload = null): array
    {
        $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : self::GRAPH . $pathOrUrl;

        return $this->http->send($method, $url, ['Authorization' => 'Bearer ' . $this->token()] + $headers, $body, $upload);
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $res = $this->http->send(
            'POST',
            self::LOGIN . '/' . rawurlencode($this->tenant) . '/oauth2/v2.0/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id'     => $this->clientId,
                'client_secret' => $this->secret,
                'scope'         => 'https://graph.microsoft.com/.default',
                'grant_type'    => 'client_credentials',
            ]),
        );

        $data = json_decode($res['body'], true) ?: [];

        if ($res['status'] !== 200 || empty($data['access_token'])) {
            $why = (string) ($data['error_description'] ?? $data['error'] ?? 'HTTP ' . $res['status']);

            throw new RuntimeException('Microsoft sign-in failed: ' . trim(strtok($why, "\r\n") ?: $why));
        }

        return $this->token = (string) $data['access_token'];
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

        $msg = (string) (json_decode($res['body'], true)['error']['message'] ?? '');

        $hint = match (true) {
            $res['status'] === 401 || $res['status'] === 403 => ' The app registration needs Files.ReadWrite.All (or Sites.Selected) with admin consent.',
            $res['status'] === 404                          => ' The site, user or drive was not found.',
            default                                         => '',
        };

        throw new RuntimeException($what . ' failed (HTTP ' . $res['status'] . ').' . $hint . ($msg !== '' ? ' ' . $msg : ''));
    }
}
