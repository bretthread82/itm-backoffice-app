<?php

declare(strict_types=1);

namespace Tests\Support\Backups;

use App\Core\Backups\Http\Transport;

/**
 * Records requests and answers from a script of [method, url fragment, response]; first match wins.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string, upload: ?array, sent: ?string}> */
    public array $requests = [];

    /** @var list<array{string, string, array}> */
    private array $script = [];

    /**
     * @param array<string,string> $headers
     */
    public function on(string $method, string $urlContains, int $status = 200, string $body = '', array $headers = []): self
    {
        $this->script[] = [$method, $urlContains, ['status' => $status, 'headers' => $headers, 'body' => $body]];

        return $this;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, ?array $upload = null): array
    {
        $sent = $upload !== null ? (string) file_get_contents($upload['path'], false, null, $upload['offset'], $upload['length']) : null;

        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'upload' => $upload, 'sent' => $sent];

        foreach ($this->script as [$m, $needle, $response]) {
            if ($m === $method && str_contains($url, $needle)) {
                return $response;
            }
        }

        return ['status' => 599, 'headers' => [], 'body' => 'no scripted response for ' . $method . ' ' . $url];
    }
}
