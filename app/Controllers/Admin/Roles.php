<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\RoleModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Backoffice;

class Roles extends BaseController
{
    public function index(): string
    {
        return view('admin/roles/index', ['title' => 'Roles', 'roles' => model(RoleModel::class)->withCounts()]);
    }

    public function new(): string
    {
        return $this->form('New role', null);
    }

    public function create(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[120]', 'description' => 'permit_empty|max_length[255]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $roles = model(RoleModel::class);
        $name  = trim((string) $this->request->getPost('name'));
        $key   = $this->uniqueKey($name);

        $roles->insert(['key' => $key, 'name' => $name, 'description' => trim((string) $this->request->getPost('description')) ?: null, 'is_system' => 0]);
        $id = (int) $roles->getInsertID();

        $this->savePermissions($id);
        service('audit')->log('role.created', 'role', $id, null, ['name' => $name, 'permissions' => $this->postedPermissionIds()]);

        return redirect()->to('admin/roles/' . $id . '/edit')->with('message', 'Role created.');
    }

    public function edit(int $id): string
    {
        return $this->form('Edit role', $this->findOr404($id));
    }

    public function update(int $id): RedirectResponse
    {
        $role = $this->findOr404($id);

        if (! $this->validate(['name' => 'required|max_length[120]', 'description' => 'permit_empty|max_length[255]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $roles  = model(RoleModel::class);
        $before = ['name' => $role['name'], 'permissions' => $roles->permissionIds($id)];

        $roles->update($id, [
            'name'        => trim((string) $this->request->getPost('name')),
            'description' => trim((string) $this->request->getPost('description')) ?: null,
        ]);

        // Super Admin is implicitly all-powerful; its grants are not editable.
        if ($role['key'] !== config(Backoffice::class)->superRole) {
            $this->savePermissions($id);
        }

        service('audit')->log('role.updated', 'role', $id, $before, ['name' => $this->request->getPost('name'), 'permissions' => $roles->permissionIds($id)]);

        return redirect()->to('admin/roles/' . $id . '/edit')->with('message', 'Role saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        $role = $this->findOr404($id);

        if ((int) $role['is_system'] === 1) {
            return redirect()->back()->with('error', 'System roles cannot be deleted.');
        }

        $members = model(RoleModel::class)->memberIds($id);
        model(RoleModel::class)->delete($id);
        service('permissions')->forgetMany($members);

        service('audit')->log('role.deleted', 'role', $id, ['name' => $role['name']]);

        return redirect()->to('admin/roles')->with('message', 'Role deleted.');
    }

    private function form(string $title, ?array $role): string
    {
        $permissions = db_connect()->table('permissions')->orderBy('module')->orderBy('key')->get()->getResultArray();
        $grouped     = [];

        foreach ($permissions as $permission) {
            $grouped[$permission['module']][] = $permission;
        }

        return view('admin/roles/form', [
            'title'    => $title,
            'role'     => $role,
            'grouped'  => $grouped,
            'selected' => $role ? model(RoleModel::class)->permissionIds((int) $role['id']) : [],
            'isSuper'  => $role && $role['key'] === config(Backoffice::class)->superRole,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        return model(RoleModel::class)->find($id) ?? throw PageNotFoundException::forPageNotFound();
    }

    /**
     * @return list<int>
     */
    private function postedPermissionIds(): array
    {
        $ids = array_map('intval', (array) $this->request->getPost('permissions'));

        if ($ids === []) {
            return [];
        }

        // Ignore any id that is not a real permission.
        $valid = db_connect()->table('permissions')->select('id')->whereIn('id', $ids)->get()->getResultArray();

        return array_map('intval', array_column($valid, 'id'));
    }

    private function savePermissions(int $roleId): void
    {
        $db = db_connect();
        $db->transStart();
        $db->table('role_permissions')->where('role_id', $roleId)->delete();

        foreach ($this->postedPermissionIds() as $permissionId) {
            $db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        $db->transComplete();

        service('permissions')->forgetMany(model(RoleModel::class)->memberIds($roleId));
    }

    private function uniqueKey(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'role';
        $key  = $base;
        $i    = 1;

        while (model(RoleModel::class)->where('key', $key)->first()) {
            $key = $base . '_' . ++$i;
        }

        return $key;
    }
}
