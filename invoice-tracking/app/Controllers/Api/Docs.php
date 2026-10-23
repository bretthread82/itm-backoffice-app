<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;

/**
 * The interactive API documentation page (Swagger UI, served from this site, nothing from a CDN).
 * It is for signed-in people; they paste a token from "API tokens" into Authorize to try calls.
 */
class Docs extends BaseController
{
    public function index(): string
    {
        return view('api/docs', ['title' => 'API documentation', 'specUrl' => site_url('api/v1/openapi.json')]);
    }
}
