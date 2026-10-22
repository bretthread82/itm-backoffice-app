<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\DepartmentModel;
use App\Models\RoleModel;
use App\Models\StaffProfileModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Exceptions\PageNotFoundException;
use RuntimeException;

class Users extends BaseController
{
    private const PER_PAGE = 15;

    public function index(): string
    {
        $filters = [
            'q'             => trim((string) $this->request->getGet('q')),
            'status'        => (string) $this->request->getGet('status'),
            'department_id' => (string) $this->request->getGet('department_id'),
            'role_id'       => (string) $this->request->getGet('role_id'),
        ];

        $profiles = model(StaffProfileModel::class);
        $total    = $profiles->listing($filters)->countAllResults();
        $page     = max(1, (int) $this->request->getGet('page'));
        $rows     = $profiles->listing($filters)->orderBy('sp.last_name')->orderBy('sp.first_name')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('admin/users/index', [
            'title'       => 'Users',
            'rows'        => $rows,
            'filters'     => $filters,
            'total'       => $total,
            'pager'       => service('pager')->makeLinks($page, self::PER_PAGE, $total, 'bootstrap'),
            'departments' => model(DepartmentModel::class)->orderBy('name')->findAll(),
            'roles'       => model(RoleModel::class)->orderBy('name')->findAll(),
        ]);
    }

    public function new(): string
    {
        return $this->form('New user', null);
    }

    public function create(): RedirectResponse
    {
        if (! $this->validate($this->rules(null))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $result = service('userService')->create($this->request->getPost(), $this->postedRoleIds());
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/users/' . $result['id'] . '/edit')
            ->with('message', 'User created.')
            ->with('temp_password', $result['password']);
    }

    public function edit(int $id): string
    {
        return $this->form('Edit user', $this->findOr404($id));
    }

    public function update(int $id): RedirectResponse
    {
        $this->findOr404($id);

        // Role-only editors submit no profile fields (they are disabled in the form).
        if (can('users.edit') && ! $this->validate($this->rules($id))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            if (can('users.edit')) {
                service('userService')->update($id, $this->request->getPost());
            }

            if (can('users.assign_roles') && $this->request->getPost('roles_present')) {
                service('userService')->setRoles($id, $this->postedRoleIds());
            }
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/users/' . $id . '/edit')->with('message', 'User saved.');
    }

    public function status(int $id): RedirectResponse
    {
        $this->findOr404($id);

        try {
            service('userService')->setStatus($id, (string) $this->request->getPost('status'));
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('message', 'Status updated.');
    }

    public function resetPassword(int $id): RedirectResponse
    {
        $this->findOr404($id);

        try {
            $password = service('userService')->resetPassword($id);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('message', 'Password reset.')->with('temp_password', $password);
    }

    public function resetMfa(int $id): RedirectResponse
    {
        $this->findOr404($id);

        try {
            service('userService')->resetMfa($id);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('message', 'Two-step verification was reset for this user.');
    }

    public function delete(int $id): RedirectResponse
    {
        $this->findOr404($id);

        try {
            service('userService')->delete($id);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/users')->with('message', 'User deleted.');
    }

    private function form(string $title, ?array $user): string
    {
        return view('admin/users/form', [
            'title'       => $title,
            'user'        => $user,
            'departments' => model(DepartmentModel::class)->orderBy('name')->findAll(),
            'roles'       => model(RoleModel::class)->orderBy('name')->findAll(),
            'userRoleIds' => $user ? service('userService')->roleIds((int) $user['id']) : [],
            'mfaMethods'  => $user ? array_keys(service('mfa')->methods((int) $user['id'])) : [],
            'managers'    => model(StaffProfileModel::class)->listing(['status' => 'active'])->orderBy('sp.last_name')->get()->getResultArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        $row = model(StaffProfileModel::class)->listing()->where('sp.user_id', $id)->get()->getRowArray();

        return $row ?? throw PageNotFoundException::forPageNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function rules(?int $id): array
    {
        $emailRule = 'required|valid_email|max_length[254]';

        if ($id === null) {
            $emailRule .= '|is_unique[auth_identities.secret]';
        } else {
            $emailRule .= "|is_unique[auth_identities.secret,user_id,{$id}]";
        }

        return [
            'email'         => $emailRule,
            'first_name'    => 'required|max_length[80]',
            'last_name'     => 'required|max_length[80]',
            'job_title'     => 'permit_empty|max_length[120]',
            'phone'         => 'permit_empty|max_length[40]',
            'mobile'        => 'permit_empty|max_length[40]',
            'location'      => 'permit_empty|max_length[120]',
            'start_date'    => 'permit_empty|valid_date[Y-m-d]',
            'department_id' => 'permit_empty|is_natural_no_zero',
            'manager_id'    => 'permit_empty|is_natural_no_zero',
            'employee_no'   => 'permit_empty|max_length[30]',
        ];
    }

    /**
     * @return list<int>
     */
    private function postedRoleIds(): array
    {
        if (! can('users.assign_roles')) {
            return [];
        }

        return array_map('intval', (array) $this->request->getPost('roles'));
    }
}
