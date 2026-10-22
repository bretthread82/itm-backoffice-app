<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\DepartmentModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

class Departments extends BaseController
{
    public function index(): string
    {
        $model = model(DepartmentModel::class);
        $edit  = (int) $this->request->getGet('edit');

        return view('admin/departments/index', [
            'title'       => 'Departments',
            'departments' => $model->withCounts(),
            'editing'     => $edit ? $model->find($edit) : null,
        ]);
    }

    public function save(): RedirectResponse
    {
        $id    = (int) $this->request->getPost('id');
        $model = model(DepartmentModel::class);

        $rules = ['name' => 'required|max_length[120]|is_unique[departments.name,id,' . $id . ']', 'parent_id' => 'permit_empty|is_natural_no_zero'];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $parent = (int) $this->request->getPost('parent_id') ?: null;

        if ($id && $parent === $id) {
            return redirect()->back()->withInput()->with('error', 'A department cannot be its own parent.');
        }

        $data = ['name' => trim((string) $this->request->getPost('name')), 'parent_id' => $parent];

        if ($id) {
            $model->find($id) ?? throw PageNotFoundException::forPageNotFound();
            $model->update($id, $data);
            service('audit')->log('department.updated', 'department', $id, null, $data);
        } else {
            $model->insert($data);
            service('audit')->log('department.created', 'department', $model->getInsertID(), null, $data);
        }

        return redirect()->to('admin/departments')->with('message', 'Department saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        $model = model(DepartmentModel::class);
        $dept  = $model->find($id) ?? throw PageNotFoundException::forPageNotFound();

        // FKs null out staff.department_id; re-parent children to the deleted node's parent.
        $model->where('parent_id', $id)->set(['parent_id' => $dept['parent_id']])->update();
        $model->delete($id);

        service('audit')->log('department.deleted', 'department', $id, ['name' => $dept['name']]);

        return redirect()->to('admin/departments')->with('message', 'Department deleted.');
    }
}
