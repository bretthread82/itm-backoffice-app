<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\RequestInterface;

/**
 * CodeIgniter's CSRF protection, except for /api. The API authenticates with a bearer token the
 * client adds itself (never a cookie the browser sends on its own), so there is nothing for a
 * forged cross-site request to ride on. Everything else is protected exactly as before.
 */
class AppCsrf extends CSRF
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $path = trim($request->getUri()->getPath(), '/');
        if ($path === 'api' || str_starts_with($path, 'api/')) {
            return null;
        }

        return parent::before($request, $arguments);
    }
}
