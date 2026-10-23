<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuditLogger;
use App\Services\SupplierImporter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * The supplier pick-list used on the invoice form: a unique supplier code and name. Global; invoices
 * store the name as text, so renaming or switching a supplier off never changes an invoice already entered.
 */
class Suppliers extends BaseController
{
    public function index(): string
    {
        $suppliers = db_connect()->query(
            'SELECT s.id, s.code, s.name, s.active,
                    (SELECT COUNT(*) FROM invoices i WHERE i.supplier = s.name) AS invoice_count
             FROM suppliers s ORDER BY s.active DESC, s.name',
        )->getResultArray();

        return view('admin/suppliers/index', ['title' => 'Suppliers', 'suppliers' => $suppliers]);
    }

    public function create(): RedirectResponse
    {
        $error = $this->invalid(null);
        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('suppliers')->insert(['code' => $this->code(), 'name' => $this->name(), 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        (new AuditLogger())->log('supplier.created', (int) auth()->id(), 'supplier', (int) $db->insertID());

        return redirect()->to('admin/suppliers')->with('message', 'Supplier added.');
    }

    public function update(int $id): RedirectResponse
    {
        $db = db_connect();
        if ($db->table('suppliers')->where('id', $id)->countAllResults() === 0) {
            return redirect()->to('admin/suppliers')->with('error', 'Supplier not found.');
        }
        $error = $this->invalid($id);
        if ($error !== null) {
            return redirect()->back()->with('error', $error);
        }

        $db->table('suppliers')->where('id', $id)->update([
            'code' => $this->code(), 'name' => $this->name(), 'active' => $this->request->getPost('active') ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditLogger())->log('supplier.updated', (int) auth()->id(), 'supplier', $id);

        return redirect()->to('admin/suppliers')->with('message', 'Supplier saved.');
    }

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

    // ------------------------------------------------------------------ helpers

    private function code(): string
    {
        return trim((string) $this->request->getPost('code'));
    }

    private function name(): string
    {
        return trim((string) $this->request->getPost('name'));
    }

    private function invalid(?int $id): ?string
    {
        $code = $this->code();
        $name = $this->name();
        if ($code === '' || mb_strlen($code) > 50) {
            return 'Enter a supplier code of up to 50 characters.';
        }
        if ($name === '' || mb_strlen($name) > 200) {
            return 'Enter a supplier name of up to 200 characters.';
        }
        if ($this->taken('code', $code, $id)) {
            return 'Another supplier already has that code.';
        }
        if ($this->taken('name', $name, $id)) {
            return 'Another supplier already has that name.';
        }

        return null;
    }

    private function taken(string $field, string $value, ?int $exceptId): bool
    {
        $q = db_connect()->table('suppliers')->where('LOWER(' . $field . ')', mb_strtolower($value));
        if ($exceptId !== null) {
            $q->where('id !=', $exceptId);
        }

        return $q->countAllResults() > 0;
    }
}
