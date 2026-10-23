<?php

declare(strict_types=1);

namespace App\Controllers\InvoiceAdmin;

use App\Core\Invoices\InvoiceAudit;
use App\Controllers\BaseController;

use App\Core\Invoices\DefaultTypeAssigner;
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
                    (SELECT COUNT(*) FROM inv_invoice_types t WHERE t.default_type_id = d.id) AS company_count
             FROM inv_default_invoice_types d ORDER BY d.name',
        )->getResultArray();
        $companies = $db->query('SELECT id, name FROM companies WHERE active = 1 ORDER BY name')->getResultArray();

        $assigned = [];
        foreach ($db->query('SELECT default_type_id, company_id FROM inv_invoice_types WHERE default_type_id IS NOT NULL')->getResultArray() as $row) {
            $assigned[(int) $row['default_type_id']][] = (int) $row['company_id'];
        }

        // Every type in the application, default copies and custom ones, for the High priority switches.
        $company  = (int) $this->request->getGet('company');
        $source   = (string) $this->request->getGet('source');
        $priority = (string) $this->request->getGet('priority');
        $q        = trim((string) $this->request->getGet('q'));
        $page     = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('inv_invoice_types t')
            ->select('t.id, t.name, t.active, t.high_priority, t.default_type_id, c.code, c.name AS company_name,
                      (SELECT COUNT(*) FROM inv_routes r WHERE r.invoice_type_id = t.id) AS route_count,
                      (SELECT COUNT(*) FROM inv_invoices i WHERE i.invoice_type_id = t.id) AS invoice_count')
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

        return view('invoices/admin/default_types/index', [
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
        $type = $db->table('inv_invoice_types')->select('id, high_priority')->where('id', $typeId)->get()->getRowArray();
        if ($type === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $new = $this->request->getPost('high_priority') ? 1 : 0;
        $old = (int) $type['high_priority'];
        if ($new !== $old) {
            $db->table('inv_invoice_types')->where('id', $typeId)->update(['high_priority' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            (new InvoiceAudit())->log('invoice_type.priority_changed', (int) auth()->id(), 'invoice_type', $typeId, ['from' => $old, 'to' => $new]);
        }

        $back = (string) $this->request->getPost('return');

        return redirect()->to(str_starts_with($back, 'invoices-admin/routes/') ? $back : 'invoices-admin/default-invoice-types')->with('message', 'High priority saved. It applies to invoices submitted from now on.');
    }





    public function create(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[200]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $name = trim((string) $this->request->getPost('name'));
        $db   = db_connect();
        if ($db->table('inv_default_invoice_types')->where('LOWER(name)', mb_strtolower($name))->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A default type with that name already exists.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('inv_default_invoice_types')->insert(['name' => $name, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        (new InvoiceAudit())->log('default_invoice_type.created', (int) auth()->id(), 'default_invoice_type', (int) $db->insertID());

        return redirect()->to('invoices-admin/default-invoice-types')->with('message', 'Default invoice type added.');
    }

    public function update(int $id): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[200]'])) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $name = trim((string) $this->request->getPost('name'));
        $db   = db_connect();
        if ($db->table('inv_default_invoice_types')->where('LOWER(name)', mb_strtolower($name))->where('id !=', $id)->countAllResults() > 0) {
            return redirect()->back()->with('error', 'A default type with that name already exists.');
        }

        // Renaming does not change types already copied into companies; the priority flag does follow them.
        $priority = $this->request->getPost('high_priority') ? 1 : 0;
        $before   = (int) ($db->table('inv_default_invoice_types')->select('high_priority')->where('id', $id)->get()->getRowArray()['high_priority'] ?? 0);
        $db->table('inv_default_invoice_types')->where('id', $id)->update([
            'name'          => $name,
            'active'        => $this->request->getPost('active') ? 1 : 0,
            'high_priority' => $priority,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $db->table('inv_invoice_types')->where('default_type_id', $id)->update(['high_priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')]);
        (new InvoiceAudit())->log('default_invoice_type.updated', (int) auth()->id(), 'default_invoice_type', $id, ['high_priority' => $priority, 'high_priority_before' => $before]);

        return redirect()->to('invoices-admin/default-invoice-types')->with('message', 'Default invoice type saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        // Company copies stay in place; only the link back to the default is cleared.
        $db = db_connect();
        $db->table('inv_invoice_types')->where('default_type_id', $id)->update(['default_type_id' => null]);
        $db->table('inv_default_invoice_types')->where('id', $id)->delete();
        (new InvoiceAudit())->log('default_invoice_type.deleted', (int) auth()->id(), 'default_invoice_type', $id);

        return redirect()->to('invoices-admin/default-invoice-types')->with('message', 'Default invoice type deleted. Company copies were kept.');
    }

    public function assign(): RedirectResponse
    {
        $typeIds    = array_values(array_unique(array_map('intval', (array) $this->request->getPost('type_ids'))));
        $companyIds = array_values(array_unique(array_map('intval', (array) $this->request->getPost('company_ids'))));
        if ($typeIds === [] || $companyIds === []) {
            return redirect()->back()->with('error', 'Choose at least one default type and one company.');
        }

        $db        = db_connect();
        $types     = $db->table('inv_default_invoice_types')->whereIn('id', $typeIds)->where('active', 1)->get()->getResultArray();
        $companies = $db->table('companies')->select('id')->whereIn('id', $companyIds)->where('active', 1)->get()->getResultArray();

        $created  = 0;
        $existing = 0;
        $assigner = new DefaultTypeAssigner($db);
        foreach ($companies as $company) {
            foreach ($types as $type) {
                $assigner->assign((int) $type['id'], (string) $type['name'], (int) $company['id']) === 'created' ? $created++ : $existing++;
            }
        }
        (new InvoiceAudit())->log('default_invoice_type.assigned', (int) auth()->id(), 'default_invoice_type', null, [
            'types' => array_map('intval', array_column($types, 'id')), 'companies' => array_map('intval', array_column($companies, 'id')),
            'created' => $created, 'already_present' => $existing,
        ]);

        return redirect()->to('invoices-admin/default-invoice-types')->with('message', sprintf(
            '%d invoice type%s added; %d already existed. Each company still needs an approval route for a type before it can be used.',
            $created,
            $created === 1 ? '' : 's',
            $existing,
        ));
    }
}
