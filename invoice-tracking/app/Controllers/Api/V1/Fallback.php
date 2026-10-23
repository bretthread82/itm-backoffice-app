<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Anything under /api/v1 that is not a real endpoint, so clients always get JSON and never an HTML page.
 */
class Fallback extends ApiController
{
    public function index(): ResponseInterface
    {
        return strtolower($this->request->getMethod()) === 'get'
            ? $this->notFound('No such endpoint.')
            : $this->error(405, 'method_not_allowed', 'This API is read-only: only GET is supported.')->setHeader('Allow', 'GET');
    }
}
