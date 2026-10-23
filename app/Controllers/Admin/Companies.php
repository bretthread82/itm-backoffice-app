<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CompanyGroupModel;
use App\Models\CompanyModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

class Companies extends BaseController
{
    public function index(): string
    {
        $edit = (int) $this->request->getGet('group');

        return view('admin/companies/index', [
            'title'     => 'Companies',
            'companies' => model(CompanyModel::class)->withGroups(),
            'groups'    => model(CompanyGroupModel::class)->orderBy('name')->findAll(),
            'editGroup' => $edit ? model(CompanyGroupModel::class)->find($edit) : null,
        ]);
    }

    public function new(): string
    {
        return $this->form('New company', null);
    }

    public function create(): RedirectResponse
    {
        if (! $this->validate($this->rules(null))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $model = model(CompanyModel::class);
        $data  = $this->data();
        $model->insert($data);
        service('audit')->log('company.created', 'company', $model->getInsertID(), null, $data);

        return redirect()->to('admin/companies')->with('message', 'Company created.');
    }

    public function edit(int $id): string
    {
        return $this->form('Edit company', model(CompanyModel::class)->find($id) ?? throw PageNotFoundException::forPageNotFound());
    }

    public function update(int $id): RedirectResponse
    {
        $before = model(CompanyModel::class)->find($id) ?? throw PageNotFoundException::forPageNotFound();

        if (! $this->validate($this->rules($id))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->data();
        model(CompanyModel::class)->update($id, $data);
        service('audit')->log('company.updated', 'company', $id, $before, $data);

        return redirect()->to('admin/companies')->with('message', 'Company saved.');
    }

    public function saveGroup(): RedirectResponse
    {
        $id    = (int) $this->request->getPost('id');
        $model = model(CompanyGroupModel::class);

        if (! $this->validate(['name' => 'required|max_length[120]|is_unique[company_groups.name,id,' . $id . ']', 'parent_id' => 'permit_empty|is_natural_no_zero'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $parent = (int) $this->request->getPost('parent_id') ?: null;

        if ($id && $parent === $id) {
            return redirect()->back()->withInput()->with('error', 'A group cannot be its own parent.');
        }

        $data = ['name' => trim((string) $this->request->getPost('name')), 'parent_id' => $parent];

        if ($id) {
            $model->find($id) ?? throw PageNotFoundException::forPageNotFound();
            $model->update($id, $data);
            service('audit')->log('company_group.updated', 'company_group', $id, null, $data);
        } else {
            $model->insert($data);
            service('audit')->log('company_group.created', 'company_group', $model->getInsertID(), null, $data);
        }

        return redirect()->to('admin/companies')->with('message', 'Group saved.');
    }

    public function deleteGroup(int $id): RedirectResponse
    {
        $group = model(CompanyGroupModel::class)->find($id) ?? throw PageNotFoundException::forPageNotFound();

        // FKs null out companies.group_id and child parent_id; groups never affect access.
        model(CompanyGroupModel::class)->delete($id);
        service('audit')->log('company_group.deleted', 'company_group', $id, ['name' => $group['name']]);

        return redirect()->to('admin/companies')->with('message', 'Group deleted.');
    }

    private function form(string $title, ?array $company): string
    {
        return view('admin/companies/form', [
            'title'   => $title,
            'company' => $company,
            'groups'  => model(CompanyGroupModel::class)->orderBy('name')->findAll(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function rules(?int $id): array
    {
        return [
            'code'     => 'required|alpha_dash|max_length[30]|is_unique[companies.code,id,' . (int) $id . ']',
            'name'     => 'required|max_length[160]',
            'group_id' => 'permit_empty|is_natural_no_zero',
            'timezone' => 'required|in_list[' . implode(',', \DateTimeZone::listIdentifiers()) . ']',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return [
            'code'     => strtoupper(trim((string) $this->request->getPost('code'))),
            'name'     => trim((string) $this->request->getPost('name')),
            'group_id' => (int) $this->request->getPost('group_id') ?: null,
            'timezone' => (string) $this->request->getPost('timezone'),
            'active'   => $this->request->getPost('active') ? 1 : 0,
        ];
    }
}
