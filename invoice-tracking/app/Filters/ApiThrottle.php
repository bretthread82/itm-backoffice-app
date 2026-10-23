<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * API rate limit: at most N requests per minute for each client address and token (default 120,
 * `api-throttle:60` for 60). Unlike the form throttle it counts every method, and it answers in JSON.
 * It runs before authentication so bad tokens are limited too.
 */
class ApiThrottle implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $limit = max(1, (int) ($arguments[0] ?? 120));
        $key   = 'api_' . sha1($request->getIPAddress() . '|' . $request->getHeaderLine('Authorization'));

        if (service('throttler')->check($key, $limit, MINUTE)) {
            return null;
        }

        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) max(1, (int) service('throttler')->getTokenTime()))
            ->setJSON(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Slow down and retry shortly.']]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
