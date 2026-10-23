<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use App\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

class Companies extends ApiController
{
    public function index(): ResponseInterface
    {
        $groups = array_column(db_connect()->table('company_groups')->select('id, name')->get()->getResultArray(), 'name', 'id');

        $rows = array_map(static fn (array $c): array => [
            'id'        => (int) $c['id'],
            'code'      => $c['code'],
            'name'      => $c['name'],
            'sub_group' => $c['group_id'] !== null ? ($groups[$c['group_id']] ?? null) : null,
        ], (new ReportService())->visibleCompanies($this->uid()));

        return $this->ok(['data' => $rows]);
    }
}
