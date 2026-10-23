<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards the workflow settings (invoice types, suppliers, approval routes, route variants): administrators,
 * managers, and the narrower Workflow Manager level.
 */
class WorkflowFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        $allowed = $user !== null && $user->active
            && ((int) ($user->is_admin ?? 0) === 1 || (int) ($user->is_manager ?? 0) === 1 || (int) ($user->is_workflow ?? 0) === 1);

        if (! $allowed) {
            return redirect()->to('/')->with('error', 'Workflow access is required.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
