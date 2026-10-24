<?php

declare(strict_types=1);

namespace App\Controllers\Receivables;

use App\Controllers\BaseController;
use App\Core\Receivables\ArDelegationResolver;
use App\Core\Receivables\ReceivablesException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Approval delegations for customer invoices. Everyone manages their own; people with receivables.manage can also see
 * every delegation and set one up on someone's behalf (?all=1), for example when a colleague is unexpectedly away.
 */
class Delegations extends BaseController
{
    public function index(): string
    {
        $uid   = (int) auth()->id();
        $db    = db_connect();
        $admin = can('receivables.manage') && $this->request->getGet('all') !== null;

        $users = $db->query(
            'SELECT id, display_name AS name FROM inv_users WHERE active = 1' . ($admin ? '' : ' AND id <> ?') . ' ORDER BY name',
            $admin ? [] : [$uid],
        )->getResultArray();

        $companies = $db->table('companies')->select('id, code')->orderBy('code');
        service('scope')->restrict($companies, $uid, 'companies.id');

        return view('receivables/delegations', [
            'title'       => $admin ? 'All delegations' : 'My delegations',
            'delegations' => $admin ? (new ArDelegationResolver())->listAll() : (new ArDelegationResolver())->listFor($uid),
            'allUsers'    => $users,
            'users'       => $users,
            'companies'   => $companies->get()->getResultArray(),
            'me'          => $uid,
            'action'      => 'receivables/delegations' . ($admin ? '?all=1' : ''),
            'endBase'     => 'receivables/delegations',
            'admin'       => $admin,
        ]);
    }

    public function create(): RedirectResponse
    {
        $uid       = (int) auth()->id();
        $admin     = can('receivables.manage') && $this->request->getGet('all') !== null;
        $company   = (string) $this->request->getPost('company_id');
        $back      = 'receivables/delegations' . ($admin ? '?all=1' : '');
        $delegator = $admin ? (int) $this->request->getPost('delegator_id') : $uid;

        try {
            (new ArDelegationResolver())->create(
                $uid,
                $delegator,
                (int) $this->request->getPost('delegate_id'),
                $company !== '' ? (int) $company : null,
                $this->utc((string) $this->request->getPost('starts_at')),
                $this->utc((string) $this->request->getPost('ends_at')),
                (string) $this->request->getPost('reason'),
            );
        } catch (ReceivablesException $e) {
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
            (new ArDelegationResolver())->revoke((int) auth()->id(), $id);
        } catch (ReceivablesException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('receivables/delegations')->with('message', 'Delegation ended.');
    }
}
