<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\AccessScope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base for the JSON API. Everything answers in one shape, never reads the browser session, and
 * leaves visibility to AccessScope exactly as the screens do.
 */
abstract class ApiController extends BaseController
{
    protected const MAX_PER_PAGE = 100;

    /** The token owner (the ApiAuth filter has already authenticated them). */
    protected function uid(): int
    {
        return (int) auth('tokens')->id();
    }

    /** @param array<string, mixed> $body */
    protected function ok(array $body, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')->setJSON($body);
    }

    protected function error(int $status, string $code, string $message): ResponseInterface
    {
        return $this->ok(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected function notFound(string $message = 'Not found.'): ResponseInterface
    {
        return $this->error(404, 'not_found', $message);
    }

    /**
     * The ?company= filter. Null means "not given"; false means it is not a company this user can see
     * (the same answer whether it does not exist or is hidden).
     */
    protected function companyParam(): int|false
    {
        $raw = $this->request->getGet('company');
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (! ctype_digit((string) $raw) || ! (new AccessScope())->canViewCompany($this->uid(), (int) $raw)) {
            return false;
        }
        if (db_connect()->table('companies')->where('id', (int) $raw)->countAllResults() === 0) {
            return false;
        }

        return (int) $raw;
    }

    /**
     * @return array{0: int, 1: int} page, per_page
     */
    protected function paging(): array
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = (int) $this->request->getGet('per_page');
        $perPage = $perPage > 0 ? min($perPage, self::MAX_PER_PAGE) : 25;

        return [$page, $perPage];
    }

    /**
     * @return array{page: int, per_page: int, total: int, pages: int}
     */
    protected function meta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) max(1, ceil($total / $perPage))];
    }
}
