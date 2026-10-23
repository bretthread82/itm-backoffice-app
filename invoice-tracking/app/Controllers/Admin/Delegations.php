<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Account;
use App\Services\DelegationResolver;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Administrators can see every delegation and set one up on someone's behalf (for example when
 * a colleague is unexpectedly away).
 */
class Delegations extends Account
{
    public function index(): string
    {
        $db = db_connect();

        return view('account/delegations', [
            'title'       => 'Delegations',
            'delegations' => (new DelegationResolver())->listAll(),
            'allUsers'    => $db->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS name FROM users WHERE active = 1 ORDER BY name")->getResultArray(),
            'users'       => $db->query("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS name FROM users WHERE active = 1 ORDER BY name")->getResultArray(),
            'companies'   => $db->table('companies')->select('id, code')->orderBy('code')->get()->getResultArray(),
            'me'          => (int) auth()->id(),
            'action'      => 'admin/delegations',
            'endBase'     => 'admin/delegations',
            'admin'       => true,
        ]);
    }

    public function create(): RedirectResponse
    {
        return $this->makeDelegation((int) auth()->id(), (int) $this->request->getPost('delegator_id'), 'admin/delegations');
    }

    public function end(int $id): RedirectResponse
    {
        try {
            (new DelegationResolver())->revoke((int) auth()->id(), $id);
        } catch (\App\Services\WorkflowException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/delegations')->with('message', 'Delegation ended.');
    }
}
