<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CompanyGroupModel;
use App\Models\CompanyModel;
use App\Services\AuditLogger;
use App\Services\LogoStore;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Exceptions\PageNotFoundException;

class Companies extends BaseController
{
    public function index(): string
    {
        return view('admin/companies/index', [
            'title'     => 'Companies',
            'companies' => model(CompanyModel::class)->withGroups(),
        ]);
    }

    public function new(): string
    {
        return $this->form(['code' => '', 'name' => '', 'group_id' => null, 'sharepoint_connection_id' => null, 'active' => 1, 'logo_file' => null], null);
    }

    public function create(): RedirectResponse
    {
        $data = $this->input(null);
        if ($data === null) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $upload = $this->checkedLogo();
        if ($upload === false) {
            return redirect()->back()->withInput();
        }

        $id = model(CompanyModel::class)->insert($data, true);
        (new AuditLogger())->log('company.created', (int) auth()->id(), 'company', (int) $id, $data);
        $this->applyLogo((int) $id, null, $upload, false);

        return redirect()->to('admin/companies')->with('message', 'Company created.');
    }

    public function edit(int $id): string
    {
        return $this->form($this->find($id), $id);
    }

    public function update(int $id): RedirectResponse
    {
        $company = $this->find($id);
        $data    = $this->input($id);
        if ($data === null) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        $upload = $this->checkedLogo();
        if ($upload === false) {
            return redirect()->back()->withInput();
        }

        model(CompanyModel::class)->update($id, $data);
        (new AuditLogger())->log('company.updated', (int) auth()->id(), 'company', $id, $data);
        $this->applyLogo($id, $company['logo_file'] ?? null, $upload, (bool) $this->request->getPost('remove_logo'));

        return redirect()->to('admin/companies')->with('message', 'Company updated.');
    }

    /**
     * The uploaded logo, null when none was chosen, false (with an error flashed) when it is not acceptable.
     */
    private function checkedLogo(): UploadedFile|null|false
    {
        $file = $this->request->getFile('logo');
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $problem = (new LogoStore())->validate($file);
        if ($problem !== null) {
            session()->setFlashdata('error', $problem);

            return false;
        }

        return $file;
    }

    /** Replaces or removes the stored logo after the company row is saved. */
    private function applyLogo(int $id, ?string $current, ?UploadedFile $upload, bool $remove): void
    {
        $store = new LogoStore();
        if ($upload !== null) {
            $saved = $store->store($upload);
            $store->delete($current);
            model(CompanyModel::class)->update($id, ['logo_file' => $saved['file'], 'logo_mime' => $saved['mime']]);
        } elseif ($remove && $current !== null) {
            $store->delete($current);
            model(CompanyModel::class)->update($id, ['logo_file' => null, 'logo_mime' => null]);
        } else {
            return;
        }
        (new AuditLogger())->log('company.logo_changed', (int) auth()->id(), 'company', $id);
    }

    /**
     * @param array<string, mixed> $company
     */
    private function form(array $company, ?int $id): string
    {
        return view('admin/companies/form', [
            'title'       => $id === null ? 'New company' : 'Edit company',
            'company'     => $company,
            'id'          => $id,
            'groups'      => model(CompanyGroupModel::class)->orderBy('name')->findAll(),
            'connections' => db_connect()->table('sharepoint_connections')->select('id, name')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    /**
     * @return array<string, mixed>|null null when validation fails
     */
    private function input(?int $id): ?array
    {
        $rules = [
            'code'  => 'required|max_length[50]|alpha_dash|is_unique[companies.code,id,' . ($id ?? 0) . ']',
            'name'  => 'required|max_length[150]',
        ];
        if (! $this->validate($rules)) {
            return null;
        }

        $post = $this->request->getPost();

        return [
            'code'                     => strtoupper(trim((string) $post['code'])),
            'name'                     => trim((string) $post['name']),
            'group_id'                 => ($post['group_id'] ?? '') !== '' ? (int) $post['group_id'] : null,
            'sharepoint_connection_id' => ($post['sharepoint_connection_id'] ?? '') !== '' ? (int) $post['sharepoint_connection_id'] : null,
            'active'                   => isset($post['active']) ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        $company = model(CompanyModel::class)->find($id);
        if ($company === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $company;
    }
}
