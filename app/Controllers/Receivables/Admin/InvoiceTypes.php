<?php

declare(strict_types=1);

namespace App\Controllers\Receivables\Admin;

use App\Core\Receivables\ArAudit;
use App\Controllers\BaseController;

use App\Core\Receivables\ArDefaultTypeAssigner;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Default invoice types. Assigning copies a type into each chosen company; the copy is an
 * ordinary per-company type (it still needs an active route before it can be used on an invoice).
 */
class InvoiceTypes extends BaseController
{
    private const PER_PAGE = 100;

    public function index(): string
    {
        $db    = db_connect();
        $types = $db->query(
            'SELECT d.id, d.name, d.active, d.high_priority,
                    (SELECT COUNT(*) FROM ar_invoice_types t WHERE t.default_type_id = d.id) AS company_count
             FROM ar_default_invoice_types d ORDER BY d.name',
        )->getResultArray();
        $companies = $db->query('SELECT id, name FROM companies WHERE active = 1 ORDER BY name')->getResultArray();

        $assigned = [];
        foreach ($db->query('SELECT default_type_id, company_id FROM ar_invoice_types WHERE default_type_id IS NOT NULL')->getResultArray() as $row) {
            $assigned[(int) $row['default_type_id']][] = (int) $row['company_id'];
        }

        // Every type in the application, default copies and custom ones, for the High priority switches.
        $company  = (int) $this->request->getGet('company');
        $source   = (string) $this->request->getGet('source');
        $priority = (string) $this->request->getGet('priority');
        $q        = trim((string) $this->request->getGet('q'));
        $page     = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('ar_invoice_types t')
            ->select('t.id, t.name, t.active, t.high_priority, t.default_type_id, c.code, c.name AS company_name,
                      (SELECT COUNT(*) FROM ar_routes r WHERE r.invoice_type_id = t.id) AS route_count,
                      (SELECT COUNT(*) FROM ar_invoices i WHERE i.invoice_type_id = t.id) AS invoice_count')
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

        return view('receivables/admin/types/index', [
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
        $type = $db->table('ar_invoice_types')->select('id, high_priority')->where('id', $typeId)->get()->getRowArray();
        if ($type === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $new = $this->request->getPost('high_priority') ? 1 : 0;
        $old = (int) $type['high_priority'];
        if ($new !== $old) {
            $db->table('ar_invoice_types')->where('id', $typeId)->update(['high_priority' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            (new ArAudit())->log('ar.invoice_type_priority_changed', (int) auth()->id(), 'ar_invoice_type', $typeId, ['from' => $old, 'to' => $new]);
        }

        $back = (string) $this->request->getPost('return');

        return redirect()->to(str_starts_with($back, 'receivables-admin/routes/') ? $back : 'receivables-admin/invoice-types')->with('message', 'High priority saved. It applies to invoices submitted from now on.');
    }





    public function create(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[200]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $name = trim((string) $this->request->getPost('name'));
        $db   = db_connect();
        if ($db->table('ar_default_invoice_types')->where('LOWER(name)', mb_strtolower($name))->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A default type with that name already exists.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('ar_default_invoice_types')->insert(['name' => $name, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        (new ArAudit())->log('ar.default_invoice_type_created', (int) auth()->id(), 'ar_default_invoice_type', (int) $db->insertID());

        return redirect()->to('receivables-admin/invoice-types')->with('message', 'Default invoice type added.');
    }

    public function update(int $id): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[200]'])) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $name = trim((string) $this->request->getPost('name'));
        $db   = db_connect();
        if ($db->table('ar_default_invoice_types')->where('LOWER(name)', mb_strtolower($name))->where('id !=', $id)->countAllResults() > 0) {
            return redirect()->back()->with('error', 'A default type with that name already exists.');
        }

        // Renaming does not change types already copied into companies; the priority flag does follow them.
        $priority = $this->request->getPost('high_priority') ? 1 : 0;
        $before   = (int) ($db->table('ar_default_invoice_types')->select('high_priority')->where('id', $id)->get()->getRowArray()['high_priority'] ?? 0);
        $db->table('ar_default_invoice_types')->where('id', $id)->update([
            'name'          => $name,
            'active'        => $this->request->getPost('active') ? 1 : 0,
            'high_priority' => $priority,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $db->table('ar_invoice_types')->where('default_type_id', $id)->update(['high_priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')]);
        (new ArAudit())->log('ar.default_invoice_type_updated', (int) auth()->id(), 'ar_default_invoice_type', $id, ['high_priority' => $priority, 'high_priority_before' => $before]);

        return redirect()->to('receivables-admin/invoice-types')->with('message', 'Default invoice type saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        // Company copies stay in place; only the link back to the default is cleared.
        $db = db_connect();
        $db->table('ar_invoice_types')->where('default_type_id', $id)->update(['default_type_id' => null]);
        $db->table('ar_default_invoice_types')->where('id', $id)->delete();
        (new ArAudit())->log('ar.default_invoice_type_deleted', (int) auth()->id(), 'ar_default_invoice_type', $id);

        return redirect()->to('receivables-admin/invoice-types')->with('message', 'Default invoice type deleted. Company copies were kept.');
    }

    public function assign(): RedirectResponse
    {
        $typeIds    = array_values(array_unique(array_map('intval', (array) $this->request->getPost('type_ids'))));
        $companyIds = array_values(array_unique(array_map('intval', (array) $this->request->getPost('company_ids'))));
        if ($typeIds === [] || $companyIds === []) {
            return redirect()->back()->with('error', 'Choose at least one default type and one company.');
        }

        $db        = db_connect();
        $types     = $db->table('ar_default_invoice_types')->whereIn('id', $typeIds)->where('active', 1)->get()->getResultArray();
        $companies = $db->table('companies')->select('id')->whereIn('id', $companyIds)->where('active', 1)->get()->getResultArray();

        $created  = 0;
        $existing = 0;
        $assigner = new ArDefaultTypeAssigner($db);
        foreach ($companies as $company) {
            foreach ($types as $type) {
                $assigner->assign((int) $type['id'], (string) $type['name'], (int) $company['id']) === 'created' ? $created++ : $existing++;
            }
        }
        (new ArAudit())->log('ar.default_invoice_type_assigned', (int) auth()->id(), 'ar_default_invoice_type', null, [
            'types' => array_map('intval', array_column($types, 'id')), 'companies' => array_map('intval', array_column($companies, 'id')),
            'created' => $created, 'already_present' => $existing,
        ]);

        return redirect()->to('receivables-admin/invoice-types')->with('message', sprintf(
            '%d invoice type%s added; %d already existed. Each company still needs an approval route for a type before it can be used.',
            $created,
            $created === 1 ? '' : 's',
            $existing,
        ));
    }
}
