<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Services\AccessScope;
use App\Services\LogoStore;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Serves logos to signed-in users. A company logo only goes to people who can see that company;
 * the group logo is the brand and goes to everyone signed in.
 */
class Logos extends BaseController
{
    public function company(int $id): ResponseInterface
    {
        if (! (new AccessScope())->canViewCompany((int) auth()->id(), $id)) {
            throw PageNotFoundException::forPageNotFound();
        }
        $company = model(CompanyModel::class)->find($id);

        return $this->send($company['logo_file'] ?? null, $company['logo_mime'] ?? null);
    }

    public function group(): ResponseInterface
    {
        return $this->send((string) setting('AppSettings.groupLogoFile'), (string) setting('AppSettings.groupLogoMime'));
    }

    private function send(?string $name, ?string $mime): ResponseInterface
    {
        $bytes = in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? (new LogoStore())->read($name) : null;
        if ($bytes === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setContentType($mime, '')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setBody($bytes);
    }
}
