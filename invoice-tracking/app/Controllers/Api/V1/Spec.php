<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The OpenAPI document. It describes the API and holds no data, so it needs no token (tools and the
 * docs page fetch it directly); the server URL is filled in from this site's own address.
 */
class Spec extends ApiController
{
    public const FILE = APPPATH . 'Docs/openapi.json';

    public function index(): ResponseInterface
    {
        $spec = json_decode((string) file_get_contents(self::FILE), true);
        if (! is_array($spec)) {
            return $this->error(500, 'spec_unavailable', 'The API description could not be read.');
        }
        $spec['servers'] = [['url' => rtrim(site_url('api/v1'), '/'), 'description' => 'This server']];

        return $this->response
            ->setHeader('Cache-Control', 'public, max-age=300')
            ->setJSON($spec);
    }
}
