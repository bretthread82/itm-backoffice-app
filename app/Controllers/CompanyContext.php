<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Switches the company the signed-in user is working in (UI narrowing only).
 */
class CompanyContext extends BaseController
{
    public function set(): RedirectResponse
    {
        $ok = service('activeCompany')->set((int) auth()->id(), (int) $this->request->getPost('company_id'));

        // Only same-site relative paths are followed.
        $back = (string) $this->request->getPost('return');
        $to   = $back !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $back) ? $back : '/';

        return redirect()->to($to)->with($ok ? 'message' : 'error', $ok ? 'Company changed.' : 'You cannot view that company.');
    }
}
