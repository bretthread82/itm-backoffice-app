<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Filters\AuthRates;

/**
 * Shield's per-IP throttle (10 requests/minute), applied to sign-in
 * submissions only so that simply viewing or refreshing the form never
 * counts against anyone.
 */
class LoginThrottle implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'post') {
            return null;
        }

        return (new AuthRates())->before($request, $arguments);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
