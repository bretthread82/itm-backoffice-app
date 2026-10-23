<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Microsoft Graph, app-only (client credentials). The app registration needs the "Sites.Selected"
 * application permission, granted write access to just the one site: it cannot see the rest of SharePoint.
 */
final class GraphClient implements SharePointClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Files up to this size are uploaded in one request; larger ones use an upload session. */
    public const SIMPLE_UPLOAD_MAX = 4 * 1024 * 1024;

    /** Upload session chunk size: must be a multiple of 320 KiB. */
    public const CHUNK = 10 * 327680;

    private ClientInterface $http;

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokens = [];

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 60, 'connect_timeout' => 10]);
    }

    // ------------------------------------------------------------------ test

    public function test(array $c): array
    {
        $site  = $this->site($c);
        $drive = $this->drive($c, $site['id']);

        $listName = null;
        if ($c['list'] !== '') {
            $listId   = $this->listId($c, $site['id']);
            $listName = $c['list'];
            $this->assertColumnsExist($c, $site['id'], $listId);
        }

        return ['site' => $site['name'], 'library' => $drive['name'], 'list' => $listName];
    }

    // ------------------------------------------------------------------ store

    public function store(array $c, array $payload, string $pdfPath, string $pdfName, ?string $existingFileUrl, ?string $existingItemId, callable $progress): SharePointResult
    {
        $site = $this->site($c);

        $fileUrl = $existingFileUrl;
        $fileId  = null;
        if ($fileUrl === null || $fileUrl === '') {
            $drive = $this->drive($c, $site['id']);
            $path  = $this->filePath($c, $payload, $pdfName);
            $up    = filesize($pdfPath) <= self::SIMPLE_UPLOAD_MAX
                ? $this->simpleUpload($c, $drive['id'], $path, $pdfPath)
                : $this->sessionUpload($c, $drive['id'], $path, $pdfPath);

            $fileUrl = (string) ($up['webUrl'] ?? '');
            $fileId  = (string) ($up['id'] ?? '');
            if ($fileUrl === '') {
                throw new SharePointException('SharePoint saved the file but did not return its address.');
            }
            $progress('file', $fileUrl);
        }

        if ($c['list'] === '') {
            // File-only connection: no list item to create.
            $marker = 'file:' . ($fileId ?: 'stored');
            $progress('item', $marker);

            return new SharePointResult($fileUrl, $marker);
        }

        if ($existingItemId !== null && $existingItemId !== '') {
            return new SharePointResult($fileUrl, $existingItemId);
        }

        $payload['file_url'] = $fileUrl;
        $fields              = $this->fields($c['mapping'], $payload);
        $listId              = $this->listId($c, $site['id']);
        $item                = $this->json('POST', self::GRAPH . '/sites/' . rawurlencode($site['id']) . '/lists/' . rawurlencode($listId) . '/items', $c, ['json' => ['fields' => $fields]]);
        $itemId              = (string) ($item['id'] ?? '');
        if ($itemId === '') {
            throw new SharePointException('SharePoint did not return the new list item.');
        }
        $progress('item', $itemId);

        return new SharePointResult($fileUrl, $itemId, isset($item['webUrl']) ? (string) $item['webUrl'] : null);
    }

    public function storeFile(array $c, array $payload, string $path, string $name, string $mime): string
    {
        $site  = $this->site($c);
        $drive = $this->drive($c, $site['id']);
        $dest  = $this->filePath($c, $payload, $name);
        $up    = filesize($path) <= self::SIMPLE_UPLOAD_MAX
            ? $this->simpleUpload($c, $drive['id'], $dest, $path, $mime)
            : $this->sessionUpload($c, $drive['id'], $dest, $path);

        $url = (string) ($up['webUrl'] ?? '');
        if ($url === '') {
            throw new SharePointException('SharePoint saved the file but did not return its address.');
        }

        return $url;
    }

    // ------------------------------------------------------------------ steps

    /**
     * @param array<string, mixed> $c
     *
     * @return array{id: string, name: string}
     */
    private function site(array $c): array
    {
        $u    = parse_url((string) $c['site_url']);
        $host = (string) ($u['host'] ?? '');
        $path = trim((string) ($u['path'] ?? ''), '/');
        if ($host === '' || ($u['scheme'] ?? '') !== 'https') {
            throw new SharePointException('The site URL is not a valid https address.');
        }

        $url  = self::GRAPH . '/sites/' . $host . ($path !== '' ? ':/' . implode('/', array_map('rawurlencode', explode('/', $path))) : '') . '?$select=id,displayName';
        $site = $this->json('GET', $url, $c);
        if (empty($site['id'])) {
            throw new SharePointException('That site was not found. Check the site URL and that the app has been granted access to it.');
        }

        return ['id' => (string) $site['id'], 'name' => (string) ($site['displayName'] ?? $c['site_url'])];
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array{id: string, name: string}
     */
    private function drive(array $c, string $siteId): array
    {
        $data = $this->json('GET', self::GRAPH . '/sites/' . rawurlencode($siteId) . '/drives?$select=id,name', $c);
        foreach ($data['value'] ?? [] as $d) {
            if (strcasecmp((string) $d['name'], (string) $c['library']) === 0) {
                return ['id' => (string) $d['id'], 'name' => (string) $d['name']];
            }
        }

        $names = implode(', ', array_map(static fn (array $d): string => '"' . $d['name'] . '"', $data['value'] ?? []));

        throw new SharePointException('The document library "' . $c['library'] . '" was not found on the site. Libraries available: ' . ($names ?: 'none') . '.');
    }

    /**
     * @param array<string, mixed> $c
     */
    private function listId(array $c, string $siteId): string
    {
        $filter = rawurlencode("displayName eq '" . str_replace("'", "''", (string) $c['list']) . "'");
        $data   = $this->json('GET', self::GRAPH . '/sites/' . rawurlencode($siteId) . '/lists?$select=id,displayName&$filter=' . $filter, $c);

        if (empty($data['value'][0]['id'])) {
            throw new SharePointException('The list "' . $c['list'] . '" was not found on the site.');
        }

        return (string) $data['value'][0]['id'];
    }

    /**
     * Every SharePoint column in the mapping must exist, or item creation would fail later.
     *
     * @param array<string, mixed> $c
     */
    private function assertColumnsExist(array $c, string $siteId, string $listId): void
    {
        $cols  = $this->json('GET', self::GRAPH . '/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/columns?$select=name&$top=500', $c);
        $names = array_map(static fn (array $col): string => (string) $col['name'], $cols['value'] ?? []);

        $missing = array_values(array_filter(array_keys($c['mapping']), static fn (string $col): bool => ! in_array($col, $names, true)));
        if ($missing !== []) {
            throw new SharePointException('These mapped columns do not exist in the list "' . $c['list'] . '": ' . implode(', ', $missing) . '. Use each column\'s internal name.');
        }
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $payload
     */
    private function filePath(array $c, array $payload, string $pdfName): string
    {
        $segments = array_filter(explode('/', (string) $c['folder']), static fn (string $s): bool => $s !== '');
        $segments[] = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($payload['company_code'] ?? 'company')) ?: 'company';
        $year       = substr((string) ($payload['invoice_date'] ?? ''), 0, 4);
        $segments[] = preg_match('/^\d{4}$/', $year) === 1 ? $year : date('Y');
        $segments[] = $pdfName;

        return implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed>
     */
    private function simpleUpload(array $c, string $driveId, string $path, string $pdfPath, string $mime = 'application/pdf'): array
    {
        return $this->json(
            'PUT',
            self::GRAPH . '/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content?@microsoft.graph.conflictBehavior=replace',
            $c,
            ['headers' => ['Content-Type' => $mime], 'body' => (string) file_get_contents($pdfPath)],
        );
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed>
     */
    private function sessionUpload(array $c, string $driveId, string $path, string $pdfPath): array
    {
        $session = $this->json('POST', self::GRAPH . '/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/createUploadSession', $c, [
            'json' => ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']],
        ]);
        $uploadUrl = (string) ($session['uploadUrl'] ?? '');
        if ($uploadUrl === '') {
            throw new SharePointException('SharePoint did not start an upload session.');
        }

        $size = (int) filesize($pdfPath);
        $fh   = fopen($pdfPath, 'rb');
        $last = [];
        try {
            for ($start = 0; $start < $size; $start += self::CHUNK) {
                $chunk = (string) fread($fh, self::CHUNK);
                $end   = $start + strlen($chunk) - 1;
                // The upload URL is pre-authorised: it must NOT carry our access token.
                $last = $this->json('PUT', $uploadUrl, $c, [
                    'headers' => ['Content-Length' => (string) strlen($chunk), 'Content-Range' => "bytes {$start}-{$end}/{$size}"],
                    'body'    => $chunk,
                ], false);
            }
        } finally {
            fclose($fh);
        }

        return $last;
    }

    /**
     * @param array<string, string> $mapping SharePoint column => invoice value key
     * @param array<string, mixed>  $payload
     *
     * @return array<string, scalar>
     */
    private function fields(array $mapping, array $payload): array
    {
        $out = [];
        foreach ($mapping as $column => $key) {
            $v = $payload[$key] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $out[$column] = match ($key) {
                'amount'       => (float) $v,
                'invoice_id'   => (int) $v,
                default        => is_scalar($v) ? (string) $v : json_encode($v),
            };
        }

        return $out;
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, array $c, array $options = [], bool $authorised = true): array
    {
        $options += ['http_errors' => true, 'headers' => []];
        if ($authorised) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->token($c);
        }
        $options['headers']['Accept'] = 'application/json';

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (RequestException $e) {
            throw new SharePointException($this->describe($e), 0, $e);
        } catch (GuzzleException $e) {
            throw new SharePointException('Could not reach SharePoint: ' . $this->clean($e->getMessage()), 0, $e);
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw new SharePointException('SharePoint returned something unexpected.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $c
     */
    private function token(array $c): string
    {
        $key = $c['tenant'] . '|' . $c['client_id'];
        if (isset($this->tokens[$key]) && $this->tokens[$key]['expires'] > time() + 60) {
            return $this->tokens[$key]['token'];
        }
        if ($c['secret'] === '') {
            throw new SharePointException('The client secret is missing.');
        }

        try {
            $response = $this->http->request('POST', 'https://login.microsoftonline.com/' . rawurlencode((string) $c['tenant']) . '/oauth2/v2.0/token', [
                'form_params' => ['client_id' => $c['client_id'], 'client_secret' => $c['secret'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials'],
            ]);
        } catch (RequestException $e) {
            $detail = $e->getResponse() !== null ? json_decode((string) $e->getResponse()->getBody(), true) : null;
            $why    = is_array($detail) ? (string) ($detail['error_description'] ?? $detail['error'] ?? '') : '';

            throw new SharePointException('Microsoft would not issue an access token' . ($why !== '' ? ': ' . $this->clean(strtok($why, "\r\n") ?: $why) : '. Check the tenant ID, client ID and client secret.'), 0, $e);
        } catch (GuzzleException $e) {
            throw new SharePointException('Could not reach Microsoft to sign in: ' . $this->clean($e->getMessage()), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (! is_array($data) || empty($data['access_token'])) {
            throw new SharePointException('Microsoft did not return an access token.');
        }

        $this->tokens[$key] = ['token' => (string) $data['access_token'], 'expires' => time() + (int) ($data['expires_in'] ?? 3000)];

        return $this->tokens[$key]['token'];
    }

    private function describe(RequestException $e): string
    {
        $response = $e->getResponse();
        $status   = $response !== null ? $response->getStatusCode() : 0;
        $err      = $response !== null ? json_decode((string) $response->getBody(), true) : null;
        $detail = is_array($err) ? (string) ($err['error']['message'] ?? '') : '';
        $code   = is_array($err) ? (string) ($err['error']['code'] ?? '') : '';

        $hint = match ($status) {
            401     => ' The access token was rejected.',
            403     => ' The app has not been granted access to this site (it needs the "Sites.Selected" permission with write access on the site).',
            404     => ' Not found.',
            429     => ' SharePoint is asking us to slow down; this will be retried.',
            default => '',
        };

        return 'SharePoint returned HTTP ' . $status . ($code !== '' ? ' (' . $code . ')' : '') . ($detail !== '' ? ': ' . $this->clean($detail) : '.') . $hint;
    }

    /** Keeps messages short and free of anything that looks like a token or secret. */
    private function clean(string $text): string
    {
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[hidden]', $text) ?? $text;
        $text = preg_replace('/(client_secret=)[^&\s]+/i', '$1[hidden]', $text) ?? $text;

        return mb_substr($text, 0, 400);
    }
}
