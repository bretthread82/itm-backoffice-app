<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Payables\DelegationResolver;
use App\Core\Payables\WorkflowException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Approval delegations. Everyone manages their own; people with payables.manage can also see every
 * delegation and set one up on someone's behalf (?all=1), for example when a colleague is unexpectedly away.
 */
class PayablesDelegations extends BaseController
{
    public function index(): string
    {
        $uid   = (int) auth()->id();
        $db    = db_connect();
        $admin = can('payables.manage') && $this->request->getGet('all') !== null;

        $users = $db->query(
            'SELECT id, display_name AS name FROM inv_users WHERE active = 1' . ($admin ? '' : ' AND id <> ?') . ' ORDER BY name',
            $admin ? [] : [$uid],
        )->getResultArray();

        $companies = $db->table('companies')->select('id, code')->orderBy('code');
        service('scope')->restrict($companies, $uid, 'companies.id');

        return view('payables/delegations', [
            'title'       => $admin ? 'All delegations' : 'My delegations',
            'delegations' => $admin ? (new DelegationResolver())->listAll() : (new DelegationResolver())->listFor($uid),
            'allUsers'    => $users,
            'users'       => $users,
            'companies'   => $companies->get()->getResultArray(),
            'me'          => $uid,
            'action'      => 'payables/delegations' . ($admin ? '?all=1' : ''),
            'endBase'     => 'payables/delegations',
            'admin'       => $admin,
        ]);
    }

    public function create(): RedirectResponse
    {
        $uid      = (int) auth()->id();
        $admin    = can('payables.manage') && $this->request->getGet('all') !== null;
        $company  = (string) $this->request->getPost('company_id');
        $back     = 'payables/delegations' . ($admin ? '?all=1' : '');
        $delegator = $admin ? (int) $this->request->getPost('delegator_id') : $uid;

        try {
            (new DelegationResolver())->create(
                $uid,
                $delegator,
                (int) $this->request->getPost('delegate_id'),
                $company !== '' ? (int) $company : null,
                $this->utc((string) $this->request->getPost('starts_at')),
                $this->utc((string) $this->request->getPost('ends_at')),
                (string) $this->request->getPost('reason'),
            );
        } catch (WorkflowException $e) {
            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('message', 'Delegation saved. The delegate can now approve for you during that period.');
    }

    /**
     * The form's datetime-local values are in the user's timezone; the resolver stores UTC.
     */
    private function utc(string $local): string
    {
        if (strtotime($local) === false) {
            return $local;
        }

        return service('timezone')->toUtc($local);
    }

    public function end(int $id): RedirectResponse
    {
        try {
            (new DelegationResolver())->revoke((int) auth()->id(), $id);
        } catch (WorkflowException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('payables/delegations')->with('message', 'Delegation ended.');
    }
}
