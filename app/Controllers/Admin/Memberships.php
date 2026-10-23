<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\StaffProfileModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Which companies a user belongs to, and which module rights (declared under 'companyAccess' in the
 * registry, e.g. submit and approve invoices) they hold in each.
 */
class Memberships extends BaseController
{
    public function edit(int $userId): string
    {
        $user = $this->findUser($userId);

        return view('admin/companies/memberships', [
            'title'       => 'Company access',
            'user'        => $user,
            'companies'   => model(CompanyModel::class)->where('active', 1)->orderBy('name')->findAll(),
            'memberships' => service('memberships')->forUser($userId),
            'capabilities' => service('memberships')->capabilities(),
            'groupLevel'  => service('scope')->isGroupLevel($userId),
        ]);
    }

    public function save(int $userId): RedirectResponse
    {
        $this->findUser($userId);

        $valid   = array_map(static fn (array $c): int => (int) $c['id'], model(CompanyModel::class)->select('id')->findAll());
        $offered = array_keys(service('memberships')->capabilities());
        $caps    = (array) $this->request->getPost('cap');
        $titles  = (array) $this->request->getPost('job_title');
        $wanted  = [];

        foreach (array_keys((array) $this->request->getPost('member')) as $companyId) {
            $companyId = (int) $companyId;

            if (! in_array($companyId, $valid, true)) {
                continue;
            }

            $wanted[$companyId] = [
                'capabilities' => array_values(array_intersect($offered, array_keys((array) ($caps[$companyId] ?? [])))),
                'job_title'    => trim((string) ($titles[$companyId] ?? '')),
            ];
        }

        $before = $this->summary(service('memberships')->forUser($userId));
        service('memberships')->sync($userId, $wanted);
        service('audit')->log('user.companies_changed', 'user', $userId, ['companies' => $before], ['companies' => $this->summary(service('memberships')->forUser($userId))]);

        return redirect()->to('admin/users/' . $userId . '/companies')->with('message', 'Company access saved.');
    }

    /**
     * Company id => capabilities held, for the audit trail.
     *
     * @param array<int, array<string, mixed>> $memberships
     *
     * @return array<int, list<string>>
     */
    private function summary(array $memberships): array
    {
        return array_map(static fn (array $m): array => $m['capabilities'], $memberships);
    }

    /**
     * @return array<string, mixed>
     */
    private function findUser(int $userId): array
    {
        return model(StaffProfileModel::class)->listing()->where('sp.user_id', $userId)->get()->getRowArray()
            ?? throw PageNotFoundException::forPageNotFound();
    }
}
