<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Workflow and day-to-day administration screens: an active manager or administrator.
 * System screens (settings, SharePoint, API tokens, group-level users) use the stricter 'admin' filter.
 */
class ManagerFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        $allowed = $user !== null && $user->active
            && ((int) ($user->is_admin ?? 0) === 1 || (int) ($user->is_manager ?? 0) === 1);

        if (! $allowed) {
            return redirect()->to('/')->with('error', 'Manager access is required.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
