<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActiveCompany;
use CodeIgniter\HTTP\RedirectResponse;

class CompanyContext extends BaseController
{
    public function set(): RedirectResponse
    {
        $ok = (new ActiveCompany())->set((int) auth()->id(), (int) $this->request->getPost('company'));

        $back = redirect()->to(site_url('/'));
        if (! $ok) {
            return $back->with('error', 'You do not have access to that company.');
        }

        // Return to the page the user was on, but only within this site.
        $from = (string) $this->request->getPost('return');
        if ($from !== '' && str_starts_with($from, '/') && ! str_starts_with($from, '//')) {
            return redirect()->to(rtrim(site_url(''), '/') . $from);
        }

        return $back;
    }
}
