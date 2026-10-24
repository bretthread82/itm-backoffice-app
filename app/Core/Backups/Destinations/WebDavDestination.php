<?php

declare(strict_types=1);

namespace App\Core\Backups\Destinations;

use App\Core\Backups\Http\Transport;
use RuntimeException;

/**
 * Any WebDAV server: Nextcloud, ownCloud, Synology, a web server with mod_dav, SharePoint's WebDAV endpoint.
 */
final class WebDavDestination extends Destination
{
    private string $base;
    private string $folder;
    private string $auth;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings, private readonly Transport $http)
    {
        $url = rtrim(trim((string) ($settings['url'] ?? '')), '/');

        if (! preg_match('#^https?://[^/\s]+#i', $url)) {
            throw new RuntimeException('Enter the WebDAV address, starting with https://.');
        }

        $this->base   = $url;
        $this->folder = self::cleanFolder((string) ($settings['folder'] ?? ''));
        $this->auth   = 'Basic ' . base64_encode((string) ($settings['username'] ?? '') . ':' . (string) ($settings['password'] ?? ''));
    }

    public function put(string $localPath, string $name): string
    {
        $name = self::validName($name);
        $size = (int) filesize($localPath);

        $this->ensureFolder();

        $url = $this->url($name);
        $res = $this->http->send('PUT', $url, $this->headers(['Content-Type' => 'application/octet-stream']), null, ['path' => $localPath, 'offset' => 0, 'length' => $size]);

        if (! in_array($res['status'], [200, 201, 204], true)) {
            throw new RuntimeException($this->describe('Upload', $res));
        }

        return ($this->folder !== '' ? $this->folder . '/' : '') . $name;
    }

    public function delete(string $name): void
    {
        $res = $this->http->send('DELETE', $this->url(self::validName($name)), $this->headers());

        if (! in_array($res['status'], [200, 204, 404], true)) {
            throw new RuntimeException($this->describe('Delete', $res));
        }
    }

    public function list(): array
    {
        $res = $this->http->send('PROPFIND', $this->url(''), $this->headers(['Depth' => '1', 'Content-Type' => 'application/xml']), '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getlastmodified/></d:prop></d:propfind>');

        if ($res['status'] === 404) {
            return [];
        }

        if ($res['status'] !== 207) {
            throw new RuntimeException($this->describe('Listing', $res));
        }

        $xml = @simplexml_load_string($res['body']);

        if ($xml === false) {
            throw new RuntimeException('The server sent a listing that could not be read.');
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $names = [];
        $self  = rtrim(rawurldecode((string) parse_url($this->url(''), PHP_URL_PATH)), '/');

        foreach ($xml->xpath('//d:response/d:href') ?: [] as $href) {
            $path = rtrim(rawurldecode((string) parse_url((string) $href, PHP_URL_PATH)), '/');

            // The folder itself is listed too; everything else is a file or sub-folder beneath it.
            if ($path !== '' && $path !== $self) {
                $names[] = basename($path);
            }
        }

        return array_values(array_unique($names));
    }

    private function ensureFolder(): void
    {
        if ($this->folder === '') {
            return;
        }

        $path = '';

        foreach (explode('/', $this->folder) as $segment) {
            $path .= '/' . rawurlencode($segment);
            $res   = $this->http->send('MKCOL', $this->base . $path, $this->headers());

            // 201 created; 405 already exists. Some servers answer 301/200 for an existing folder.
            if (! in_array($res['status'], [200, 201, 301, 405], true)) {
                throw new RuntimeException($this->describe('Creating the folder', $res));
            }
        }
    }

    private function url(string $name): string
    {
        $path = '';

        foreach (array_filter(explode('/', $this->folder)) as $segment) {
            $path .= '/' . rawurlencode($segment);
        }

        return $this->base . $path . ($name !== '' ? '/' . rawurlencode($name) : '/');
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        return ['Authorization' => $this->auth] + $extra;
    }

    /**
     * @param array{status: int, body: string} $res
     */
    private function describe(string $what, array $res): string
    {
        return match (true) {
            $res['status'] === 401 || $res['status'] === 403 => $what . ' was refused: check the user name and password.',
            $res['status'] === 507                          => $what . ' failed: the server is out of space.',
            default                                         => $what . ' failed (HTTP ' . $res['status'] . ').',
        };
    }
}
