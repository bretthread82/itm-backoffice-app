<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin screens: requires a signed-in, active user with the admin flag.
 * Runs after the 'session' filter, which handles the signed-out case.
 */
class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        if ($user === null || (int) ($user->is_admin ?? 0) !== 1 || ! $user->active) {
            return redirect()->to('/')->with('error', 'Administrator access is required.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
