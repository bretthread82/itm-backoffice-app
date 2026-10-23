<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuditLogger;
use App\Services\DefaultTypeAssigner;
use App\Services\DefaultTypeImporter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Default invoice types. Assigning copies a type into each chosen company; the copy is an
 * ordinary per-company type (it still needs an active route before it can be used on an invoice).
 */
class DefaultInvoiceTypes extends BaseController
{
    private const PER_PAGE = 100;

    public function index(): string
    {
        $db    = db_connect();
        $types = $db->query(
            'SELECT d.id, d.name, d.active, d.high_priority,
                    (SELECT COUNT(*) FROM invoice_types t WHERE t.default_type_id = d.id) AS company_count
             FROM default_invoice_types d ORDER BY d.name',
        )->getResultArray();
        $companies = $db->query('SELECT id, name FROM companies WHERE active = 1 ORDER BY name')->getResultArray();

        $assigned = [];
        foreach ($db->query('SELECT default_type_id, company_id FROM invoice_types WHERE default_type_id IS NOT NULL')->getResultArray() as $row) {
            $assigned[(int) $row['default_type_id']][] = (int) $row['company_id'];
        }

        // Every type in the application, default copies and custom ones, for the High priority switches.
        $company  = (int) $this->request->getGet('company');
        $source   = (string) $this->request->getGet('source');
        $priority = (string) $this->request->getGet('priority');
        $q        = trim((string) $this->request->getGet('q'));
        $page     = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('invoice_types t')
            ->select('t.id, t.name, t.active, t.high_priority, t.default_type_id, c.code, c.name AS company_name,
                      (SELECT COUNT(*) FROM routes r WHERE r.invoice_type_id = t.id) AS route_count,
                      (SELECT COUNT(*) FROM invoices i WHERE i.invoice_type_id = t.id) AS invoice_count')
            ->join('companies c', 'c.id = t.company_id');
        if ($company > 0) {
            $builder->where('t.company_id', $company);
        }
        if ($source === 'default') {
            $builder->where('t.default_type_id IS NOT NULL', null, false);
        } elseif ($source === 'custom') {
            $builder->where('t.default_type_id IS NULL', null, false);
        }
        if ($priority === 'on' || $priority === 'off') {
            $builder->where('t.high_priority', $priority === 'on' ? 1 : 0);
        }
        if ($q !== '') {
            $builder->like('t.name', $q);
        }
        $total = (clone $builder)->countAllResults(false);
        $all   = $builder->orderBy('c.code')->orderBy('t.name')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('admin/default_types/index', [
            'title' => 'Invoice Types', 'types' => $types, 'companies' => $companies, 'assigned' => $assigned,
            'companyTypes' => $all, 'f' => ['company' => $company, 'source' => $source, 'priority' => $priority, 'q' => $q],
            'page' => $page, 'pages' => (int) max(1, ceil($total / self::PER_PAGE)), 'total' => $total,
        ]);
    }

    /**
     * High priority on any invoice type (default copy or custom). Managers and administrators only, via the
     * route filter. Applies to invoices submitted from now on; invoices in flight keep the value they started with.
     */
    public function setTypePriority(int $typeId): RedirectResponse
    {
        $db   = db_connect();
        $type = $db->table('invoice_types')->select('id, high_priority')->where('id', $typeId)->get()->getRowArray();
        if ($type === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $new = $this->request->getPost('high_priority') ? 1 : 0;
        $old = (int) $type['high_priority'];
        if ($new !== $old) {
            $db->table('invoice_types')->where('id', $typeId)->update(['high_priority' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            (new AuditLogger())->log('invoice_type.priority_changed', (int) auth()->id(), 'invoice_type', $typeId, ['from' => $old, 'to' => $new]);
        }

        $back = (string) $this->request->getPost('return');

        return redirect()->to(str_starts_with($back, 'admin/routes/') ? $back : 'admin/default-invoice-types')->with('message', 'High priority saved. It applies to invoices submitted from now on.');
    }

    public function uploadForm(): string
    {
        return view('admin/default_types/upload', ['title' => 'Bulk upload invoice types']);
    }

    public function template(): ResponseInterface
    {
        $csv = "name,company_codes,active\n"
            . "Utilities,HCSRL;CHL,Y\n"
            . "Fuel Costs,,Y\n";

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="invoice-types-template.csv"')
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

        $importer = new DefaultTypeImporter();
        try {
            $preview = $importer->validate($importer->parse((string) $store->localPath($key)));
        } catch (Throwable $e) {
            $store->delete($key);

            return redirect()->back()->with('error', 'Could not read the file: ' . $e->getMessage());
        }

        session()->set('type_upload', ['token' => $token, 'user' => auth()->id()]);

        return view('admin/default_types/upload_preview', ['title' => 'Check the upload', 'preview' => $preview]);
    }

    public function uploadCommit(): RedirectResponse
    {
        $state = session()->get('type_upload');
        $key   = is_array($state) && preg_match('/^[a-f0-9]{32}$/', (string) $state['token']) ? 'bulk/' . $state['token'] . '.csv' : '';
        $path  = $key === '' ? null : service('fileStore')->localPath($key);
        if ($path === null || (int) $state['user'] !== (int) auth()->id()) {
            return redirect()->to('admin/default-invoice-types/upload')->with('error', 'The upload has expired. Please upload the file again.');
        }

        $importer = new DefaultTypeImporter();
        try {
            $r = $importer->commit($importer->parse($path), (int) auth()->id());
        } catch (RuntimeException $e) {
            return redirect()->to('admin/default-invoice-types/upload')->with('error', $e->getMessage());
        }

        service('fileStore')->delete($key);
        session()->remove('type_upload');

        return redirect()->to('admin/default-invoice-types')->with('message', sprintf(
            'Upload complete: %d default types created, %d updated; %d company types added, %d already existed.',
            $r['created'],
            $r['updated'],
            $r['copies_added'],
            $r['copies_existing'],
        ));
    }

    public function create(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[200]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $name = trim((string) $this->request->getPost('name'));
        $db   = db_connect();
        if ($db->table('default_invoice_types')->where('LOWER(name)', mb_strtolower($name))->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A default type with that name already exists.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('default_invoice_types')->insert(['name' => $name, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        (new AuditLogger())->log('default_invoice_type.created', (int) auth()->id(), 'default_invoice_type', (int) $db->insertID());

        return redirect()->to('admin/default-invoice-types')->with('message', 'Default invoice type added.');
    }

    public function update(int $id): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[200]'])) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $name = trim((string) $this->request->getPost('name'));
        $db   = db_connect();
        if ($db->table('default_invoice_types')->where('LOWER(name)', mb_strtolower($name))->where('id !=', $id)->countAllResults() > 0) {
            return redirect()->back()->with('error', 'A default type with that name already exists.');
        }

        // Renaming does not change types already copied into companies; the priority flag does follow them.
        $priority = $this->request->getPost('high_priority') ? 1 : 0;
        $before   = (int) ($db->table('default_invoice_types')->select('high_priority')->where('id', $id)->get()->getRowArray()['high_priority'] ?? 0);
        $db->table('default_invoice_types')->where('id', $id)->update([
            'name'          => $name,
            'active'        => $this->request->getPost('active') ? 1 : 0,
            'high_priority' => $priority,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $db->table('invoice_types')->where('default_type_id', $id)->update(['high_priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')]);
        (new AuditLogger())->log('default_invoice_type.updated', (int) auth()->id(), 'default_invoice_type', $id, ['high_priority' => $priority, 'high_priority_before' => $before]);

        return redirect()->to('admin/default-invoice-types')->with('message', 'Default invoice type saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        // Company copies stay in place; only the link back to the default is cleared.
        $db = db_connect();
        $db->table('invoice_types')->where('default_type_id', $id)->update(['default_type_id' => null]);
        $db->table('default_invoice_types')->where('id', $id)->delete();
        (new AuditLogger())->log('default_invoice_type.deleted', (int) auth()->id(), 'default_invoice_type', $id);

        return redirect()->to('admin/default-invoice-types')->with('message', 'Default invoice type deleted. Company copies were kept.');
    }

    public function assign(): RedirectResponse
    {
        $typeIds    = array_values(array_unique(array_map('intval', (array) $this->request->getPost('type_ids'))));
        $companyIds = array_values(array_unique(array_map('intval', (array) $this->request->getPost('company_ids'))));
        if ($typeIds === [] || $companyIds === []) {
            return redirect()->back()->with('error', 'Choose at least one default type and one company.');
        }

        $db        = db_connect();
        $types     = $db->table('default_invoice_types')->whereIn('id', $typeIds)->where('active', 1)->get()->getResultArray();
        $companies = $db->table('companies')->select('id')->whereIn('id', $companyIds)->where('active', 1)->get()->getResultArray();

        $created  = 0;
        $existing = 0;
        $assigner = new DefaultTypeAssigner($db);
        foreach ($companies as $company) {
            foreach ($types as $type) {
                $assigner->assign((int) $type['id'], (string) $type['name'], (int) $company['id']) === 'created' ? $created++ : $existing++;
            }
        }
        (new AuditLogger())->log('default_invoice_type.assigned', (int) auth()->id(), 'default_invoice_type', null, [
            'types' => array_map('intval', array_column($types, 'id')), 'companies' => array_map('intval', array_column($companies, 'id')),
            'created' => $created, 'already_present' => $existing,
        ]);

        return redirect()->to('admin/default-invoice-types')->with('message', sprintf(
            '%d invoice type%s added; %d already existed. Each company still needs an approval route for a type before it can be used.',
            $created,
            $created === 1 ? '' : 's',
            $existing,
        ));
    }
}
