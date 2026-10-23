<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CompanyGroupModel;
use App\Services\AuditLogger;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Sub-groups of companies. Organisational only: filters and reporting, never access.
 */
class Groups extends BaseController
{
    public function index(): string
    {
        $db     = db_connect();
        $groups = $db->query(
            'SELECT g.id, g.parent_id, g.name,
                    (SELECT COUNT(*) FROM companies c WHERE c.group_id = g.id) AS company_count,
                    (SELECT COUNT(*) FROM company_groups k WHERE k.parent_id = g.id) AS child_count
             FROM company_groups g ORDER BY g.name',
        )->getResultArray();

        return view('admin/groups/index', ['title' => 'Sub-groups', 'groups' => $groups]);
    }

    public function create(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[150]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $parent = (string) $this->request->getPost('parent_id');
        $id     = model(CompanyGroupModel::class)->insert([
            'name'      => trim((string) $this->request->getPost('name')),
            'parent_id' => $parent !== '' ? (int) $parent : null,
        ], true);
        (new AuditLogger())->log('group.created', (int) auth()->id(), 'company_group', (int) $id);

        return redirect()->to('admin/groups')->with('message', 'Sub-group created.');
    }

    public function update(int $id): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[150]'])) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $parent = (string) $this->request->getPost('parent_id');
        $parent = $parent !== '' ? (int) $parent : null;
        if ($parent === $id) {
            return redirect()->back()->with('errors', ['parent_id' => 'A sub-group cannot be its own parent.']);
        }

        model(CompanyGroupModel::class)->update($id, [
            'name'      => trim((string) $this->request->getPost('name')),
            'parent_id' => $parent,
        ]);
        (new AuditLogger())->log('group.updated', (int) auth()->id(), 'company_group', $id);

        return redirect()->to('admin/groups')->with('message', 'Sub-group updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        $db = db_connect();
        if ($db->table('companies')->where('group_id', $id)->countAllResults() > 0
            || $db->table('company_groups')->where('parent_id', $id)->countAllResults() > 0) {
            return redirect()->back()->with('error', 'Move or remove its companies and child sub-groups first.');
        }

        model(CompanyGroupModel::class)->delete($id);
        (new AuditLogger())->log('group.deleted', (int) auth()->id(), 'company_group', $id);

        return redirect()->to('admin/groups')->with('message', 'Sub-group deleted.');
    }
}
