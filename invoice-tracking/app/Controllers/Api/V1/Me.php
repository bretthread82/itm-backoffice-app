<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use App\Services\AccessScope;
use App\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

class Me extends ApiController
{
    public function index(): ResponseInterface
    {
        $user  = auth('tokens')->user();
        $token = $user->currentAccessToken();

        return $this->ok(['data' => [
            'id'             => (int) $user->id,
            'name'           => $user->display_name,
            'email'          => $user->email,
            'is_group_level' => (new AccessScope())->isGroupLevel($this->uid()),
            'companies'      => $this->companies(),
            'token'          => $token === null ? null : [
                'name'       => $token->name,
                'scopes'     => $token->scopes,
                'expires_at' => $token->expires?->toDateTimeString(),
            ],
        ]]);
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function companies(): array
    {
        return array_map(
            static fn (array $c): array => ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name']],
            (new ReportService())->visibleCompanies($this->uid()),
        );
    }
}
