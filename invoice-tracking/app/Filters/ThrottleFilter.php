<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Slows down guessing on sign-in style forms: at most N POSTs per minute per client address and path
 * (default 10, `throttle:5` for 5). Only submissions count; viewing the form is free.
 */
class ThrottleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'post') {
            return null;
        }

        $limit = max(1, (int) ($arguments[0] ?? 10));
        $key   = 'throttle_' . sha1($request->getIPAddress() . '|' . $request->getUri()->getPath());

        if (service('throttler')->check($key, $limit, MINUTE)) {
            return null;
        }

        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) max(1, (int) service('throttler')->getTokenTime()))
            ->setBody('Too many attempts. Please wait a minute and try again.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
