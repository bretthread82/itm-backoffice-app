<?php

namespace App\Filters;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Usage: ['filter' => 'can:users.view,users.edit'] (any one suffices).
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to(url_to('login'));
        }

        if (permissions()->canAny((int) auth()->id(), $arguments ?? [])) {
            return null;
        }

        return service('response')
            ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
            ->setBody(view('errors/html/forbidden'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
