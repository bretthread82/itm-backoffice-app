<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\SupplierImporter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Admin > Suppliers: the global supplier list used by every module (service('suppliers')). Maintaining it is
 * `suppliers.manage`; modules only read it. Records elsewhere reference a supplier by id and keep a name snapshot,
 * so renaming or switching a supplier off never alters history.
 */
class Suppliers extends BaseController
{
    public function index(): string
    {
        // Modules that reference suppliers add their own usage count here.
        $usage = [];

        if (service('modules')->enabled('invoices')) {
            $usage['invoice_count'] = 'SELECT COUNT(*) FROM inv_invoices WHERE supplier_id = {id}';
        }

        return view('admin/suppliers/index', ['title' => 'Suppliers', 'suppliers' => service('suppliers')->all($usage), 'showInvoices' => isset($usage['invoice_count'])]);
    }

    public function create(): RedirectResponse
    {
        try {
            service('suppliers')->create($this->input(), (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('admin/suppliers')->with('message', 'Supplier added.');
    }

    public function update(int $id): RedirectResponse
    {
        try {
            service('suppliers')->update($id, $this->input() + ['active' => (bool) $this->request->getPost('active')], (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->to('admin/suppliers')->with('error', $e->getMessage());
        }

        return redirect()->to('admin/suppliers')->with('message', 'Supplier saved.');
    }

    /** Old bookmarks from when suppliers lived inside the Invoices module. */
    public function legacy(string $rest = ''): RedirectResponse
    {
        return redirect()->to('admin/suppliers' . ($rest !== '' ? '/' . $rest : ''), 301);
    }
    // ------------------------------------------------------------------ bulk upload





    // ------------------------------------------------------------------ bulk upload

    public function uploadForm(): string
    {
        return view('admin/suppliers/upload', ['title' => 'Bulk upload suppliers']);
    }

    public function template(): ResponseInterface
    {
        $csv = "code,name,active\n"
            . "S0001,Acme Supplies Ltd,Y\n"
            . "S0002,Island Fuel Co,Y\n";

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="suppliers-template.csv"')
            ->setBody($csv);
    }

    public function uploadPreview(): RedirectResponse|string
    {
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'Choose a CSV file to upload.');
        }
        if ($file->getSize() > 1024 * 1024) {
            return redirect()->back()->with('error', 'The file is larger than 1 MB.');
        }
        if (strtolower($file->getClientExtension()) !== 'csv') {
            return redirect()->back()->with('error', 'Only .csv files are accepted.');
        }

        $store = service('fileStore');
        $token = bin2hex(random_bytes(16));
        $key   = 'bulk/' . $token . '.csv';
        $store->put($key, $file->getTempName());

        $importer = new SupplierImporter();
        try {
            $preview = $importer->validate($importer->parse((string) $store->localPath($key)));
        } catch (Throwable $e) {
            $store->delete($key);

            return redirect()->back()->with('error', 'Could not read the file: ' . $e->getMessage());
        }

        session()->set('supplier_upload', ['token' => $token, 'user' => auth()->id()]);

        return view('admin/suppliers/upload_preview', ['title' => 'Check the upload', 'preview' => $preview]);
    }

    public function uploadCommit(): RedirectResponse
    {
        $state = session()->get('supplier_upload');
        $key   = is_array($state) && preg_match('/^[a-f0-9]{32}$/', (string) $state['token']) ? 'bulk/' . $state['token'] . '.csv' : '';
        $path  = $key === '' ? null : service('fileStore')->localPath($key);
        if ($path === null || (int) $state['user'] !== (int) auth()->id()) {
            return redirect()->to('admin/suppliers/upload')->with('error', 'The upload has expired. Please upload the file again.');
        }

        $importer = new SupplierImporter();
        try {
            $r = $importer->commit($importer->parse($path), (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->to('admin/suppliers/upload')->with('error', $e->getMessage());
        }

        service('fileStore')->delete($key);
        session()->remove('supplier_upload');

        return redirect()->to('admin/suppliers')->with('message', sprintf('Upload complete: %d suppliers created, %d updated.', $r['created'], $r['updated']));
    }
    /**
     * @return array{code: string, name: string}
     */
    private function input(): array
    {
        return ['code' => (string) $this->request->getPost('code'), 'name' => (string) $this->request->getPost('name')];
    }
}
