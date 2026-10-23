<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Tenancy\AccessScope;
use App\Core\Invoices\ImagePdfBuilder;
use App\Core\Invoices\InvoiceDocuments;
use App\Core\Invoices\InvoiceFiles;
use App\Core\Invoices\InvoiceQuery;
use App\Core\Invoices\InvoiceTimeline;
use App\Core\Invoices\ReportService;
use App\Core\Invoices\WorkflowEngine;
use App\Core\Invoices\WorkflowException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

class Invoices extends BaseController
{
    private const PER_PAGE = 25;

    private const FIELDS = ['company_id', 'invoice_type_id', 'supplier', 'invoice_number', 'invoice_date', 'due_date', 'amount', 'currency', 'po_reference', 'extra_approval'];

    private WorkflowEngine $engine;
    private AccessScope $scope;

    public function __construct()
    {
        $this->engine = new WorkflowEngine();
        $this->scope  = new AccessScope();
    }

    // ------------------------------------------------------------------ list

    public function index(): string
    {
        $uid = (int) auth()->id();
        [$builder, $f] = $this->filtered($uid);
        $page = max(1, (int) $this->request->getGet('page'));

        $total    = (clone $builder)->countAllResults(false);
        $summary  = (new InvoiceQuery($this->scope))->paidUnpaid($uid, $this->request->getGet(), $this->scopedCompany((int) ($this->request->getGet('company') ?? 0)));
        $invoices = $builder->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        $companies = db_connect()->table('companies')->select('id, code, name')->orderBy('code');
        $this->scope->restrict($companies, $uid, 'companies.id');

        return view('invoices/index', [
            'title'     => 'Invoice Register',
            'invoices'  => $invoices,
            'summary'   => $summary,
            'companies' => $companies->get()->getResultArray(),
            'f'         => $f,
            'page'      => $page,
            'pages'     => (int) max(1, ceil($total / self::PER_PAGE)),
            'perPage'   => self::PER_PAGE,
            'total'     => $total,
            'canSubmit' => $this->submitCompanies($uid) !== [],
        ]);
    }

    /** CSV of the current filters, limited to what the user may see. */
    public function export(): ResponseInterface
    {
        [$builder] = $this->filtered((int) auth()->id());

        $rows = [];
        foreach ($builder->limit(5000)->get()->getResultArray() as $i) {
            $rows[] = [
                $i['invoice_number'] ?: 'Draft #' . $i['id'], $i['company_code'], $i['supplier'], $i['invoice_date'], $i['due_date'],
                $i['amount'] === null ? '' : (float) $i['amount'], $i['currency'], invoice_status_label((string) $i['status']), $i['po_reference'],
            ];
        }
        $csv = \App\Core\Invoices\Csv::build(['Invoice', 'Company', 'Supplier', 'Invoice date', 'Due date', 'Amount', 'Currency', 'Status', 'PO / reference'], $rows);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="invoices-' . date('Y-m-d') . '.csv"')
            ->setBody($csv);
    }

    /**
     * The register query for the current request, already restricted by AccessScope.
     *
     * @return array{0: \CodeIgniter\Database\BaseBuilder, 1: array<string, mixed>}
     */
    private function filtered(int $uid): array
    {
        $get = $this->request->getGet();

        return (new InvoiceQuery($this->scope))->build($uid, $get, $this->scopedCompany((int) ($get['company'] ?? 0)));
    }

    // ------------------------------------------------------------------ create

    public function new(): string|RedirectResponse
    {
        $companies = $this->submitCompanies((int) auth()->id());
        if ($companies === []) {
            return redirect()->to('invoices')->with('error', 'You have not been given permission to submit invoices for any company.');
        }

        return view('invoices/form', [
            'title'     => 'New invoice',
            'invoice'   => ['company_id' => count($companies) === 1 ? $companies[0]['id'] : null, 'currency' => setting('InvoiceSettings.defaultCurrency'), 'extra_approval' => 0],
            'id'        => null,
            'companies' => $companies,
            'suppliers' => $this->supplierOptions(),
            'file'      => null,
            'status'    => 'draft',
        ]);
    }

    /**
     * Types a user can submit for in a company, with a preview of the approval route.
     */
    public function types(): ResponseInterface
    {
        $uid       = (int) auth()->id();
        $companyId = (int) $this->request->getGet('company');
        $db        = db_connect();

        if (! in_array($companyId, array_column($this->submitCompanies($uid), 'id'), true)) {
            return $this->response->setStatusCode(403)->setJSON(['types' => []]);
        }

        $types = [];
        foreach ($db->table('inv_invoice_types')->where(['company_id' => $companyId, 'active' => 1])->orderBy('name')->get()->getResultArray() as $t) {
            $default = $db->table('inv_routes')->where(['company_id' => $companyId, 'invoice_type_id' => $t['id'], 'variant' => 'default', 'active' => 1])->get()->getRowArray();
            if ($default === null) {
                continue;
            }
            $steps = $db->table('inv_route_steps s')->select('s.step_type, s.assignee_label, j.name AS role')
                ->join('inv_job_titles j', 'j.id = s.job_title_id', 'left')->where('s.route_id', $default['id'])->orderBy('s.step_order')->get()->getResultArray();
            $variant = $db->table('inv_routes')->where(['company_id' => $companyId, 'invoice_type_id' => $t['id'], 'variant !=' => 'default', 'active' => 1, 'variant_trigger' => 'manual_flag'])->countAllResults() > 0;

            $types[] = [
                'id' => (int) $t['id'], 'name' => $t['name'], 'extra_flag' => $variant,
                'steps' => array_map(static fn (array $s): array => ['type' => $s['step_type'], 'role' => (string) $s['role'], 'who' => (string) $s['assignee_label']], $steps),
            ];
        }

        return $this->response->setJSON(['types' => $types]);
    }

    public function create(): RedirectResponse
    {
        $uid = (int) auth()->id();

        $upload = null;
        try {
            $this->assertRequiredDetails($this->input());
            $upload = $this->checkedUpload();           // a bad file stops everything: no orphan draft
            $id     = $this->engine->createDraft($uid, $this->input());
            $this->storeUpload($id, $uid, $upload);
        } catch (WorkflowException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } finally {
            $this->discardUpload($upload);
        }

        return $this->afterSave($id, $uid, (string) $this->request->getPost('action'));
    }

    // ------------------------------------------------------------------ view

    public function show(int $id): string
    {
        $uid = (int) auth()->id();
        $inv = $this->visible($id);

        $files = new InvoiceFiles();
        $file  = $files->current($id);

        $steps = $this->engine->stepsOf($inv);
        if ($steps === [] && $inv['status'] === 'draft') {
            try {
                $steps = $this->engine->selectRoute($inv)['steps'];
            } catch (WorkflowException) {
                $steps = [];
            }
        }

        [$timeline, $actedByStep] = (new InvoiceTimeline())->for($id);
        $can = $this->engine->canAct($id, $uid);

        $isSubmitter = (int) $inv['submitted_by'] === $uid;

        return view('invoices/show', [
            'title'       => 'Invoice ' . ($inv['invoice_number'] ?: '#' . $id),
            'inv'         => $inv,
            'file'        => $file,
            'fileExists'  => $file !== null && $files->path($file) !== null,
            'documents'   => (new InvoiceDocuments())->forInvoice($id),
            'canAttach'   => $this->engine->canAttach($id, $uid),
            'steps'       => $steps,
            'acted'       => $actedByStep,
            'timeline'    => $timeline,
            'can'         => $can,
            'canEdit'     => $isSubmitter && in_array($inv['status'], WorkflowEngine::EDITABLE, true),
            'isSubmitter' => $isSubmitter,
            'problems'    => $inv['status'] === 'draft' && $isSubmitter ? $this->engine->problemsForSubmit($id) : [],
            'currentLabel' => ($step = $this->engine->currentStep($inv)) ? $this->engine->label($step) : '',
            'isGroupLevel' => $this->scope->isGroupLevel($uid),
        ]);
    }

    public function file(int $id): ResponseInterface
    {
        $this->visible($id);

        $files = new InvoiceFiles();
        $file  = $files->current($id);
        $path  = $file !== null ? $files->path($file) : null;
        if ($path === null) {
            throw PageNotFoundException::forPageNotFound('The invoice file is no longer stored on this server.');
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $file['original_name']) ?: 'invoice.pdf';
        $disp = $this->request->getGet('download') ? 'attachment' : 'inline';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', $disp . '; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody((string) file_get_contents($path));
    }

    // ------------------------------------------------------------------ edit / submit

    public function edit(int $id): string|RedirectResponse
    {
        $uid = (int) auth()->id();
        $inv = $this->visible($id);

        if ((int) $inv['submitted_by'] !== $uid || ! in_array($inv['status'], WorkflowEngine::EDITABLE, true)) {
            return redirect()->to('invoices/' . $id)->with('error', 'This invoice cannot be edited.');
        }

        return view('invoices/form', [
            'title'     => 'Edit invoice',
            'invoice'   => $inv,
            'id'        => $id,
            'companies' => $this->submitCompanies($uid),
            'suppliers' => $this->supplierOptions(),
            'file'      => (new InvoiceFiles())->current($id),
            'status'    => $inv['status'],
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $uid = (int) auth()->id();
        $inv = $this->visible($id);

        $upload = null;
        try {
            $this->assertRequiredDetails($this->input(), (string) $inv['supplier']);
            $upload = $this->checkedUpload();           // a bad file changes nothing
            $this->engine->updateDetails($id, $uid, $this->input());
            $this->storeUpload($id, $uid, $upload);
        } catch (WorkflowException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } finally {
            $this->discardUpload($upload);
        }

        return $this->afterSave($id, $uid, (string) $this->request->getPost('action'));
    }

    public function submit(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->submit($id, (int) auth()->id()), 'Invoice submitted for approval.');
    }

    public function resubmit(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->resubmit($id, (int) auth()->id(), (string) $this->request->getPost('note')), 'Invoice resubmitted for approval.');
    }

    public function cancel(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->cancel($id, (int) auth()->id()), 'Invoice cancelled.');
    }

    public function note(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->addNote($id, (int) auth()->id(), (string) $this->request->getPost('note')), 'Note added.');
    }

    public function addDocument(int $id): RedirectResponse
    {
        return $this->guarded($id, function () use ($id): void {
            $file = $this->request->getFile('document');
            if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
                throw new WorkflowException('Choose a file to attach.');
            }
            if (! $file->isValid()) {
                throw new WorkflowException('The file could not be uploaded: ' . $file->getErrorString() . '.');
            }

            $this->engine->addDocument($id, (int) auth()->id(), $file->getTempName(), $file->getClientName(), (string) $this->request->getPost('note'));
        }, 'Document attached.');
    }

    public function document(int $id, int $docId): ResponseInterface
    {
        $this->visible($id);

        $docs = new InvoiceDocuments();
        $doc  = $docs->find($id, $docId);
        $path = $doc !== null ? $docs->path($doc) : null;
        if ($doc === null || $path === null) {
            throw PageNotFoundException::forPageNotFound('The document is no longer stored on this server.');
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $doc['original_name']) ?: 'document';
        $disp = InvoiceDocuments::isInline((string) $doc['mime']) && ! $this->request->getGet('download') ? 'inline' : 'attachment';

        return $this->response
            ->setHeader('Content-Type', (string) $doc['mime'])
            ->setHeader('Content-Disposition', $disp . '; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody((string) file_get_contents($path));
    }

    public function act(int $id): RedirectResponse
    {
        $uid      = (int) auth()->id();
        $decision = (string) $this->request->getPost('decision');
        $note     = (string) $this->request->getPost('note');

        return $this->guarded($id, function () use ($id, $uid, $decision, $note): void {
            match ($decision) {
                'approve' => $this->engine->approve($id, $uid, $note, [
                    'payment_reference' => $this->request->getPost('payment_reference'),
                    'payment_date'      => $this->request->getPost('payment_date'),
                ]),
                'return'  => $this->engine->returnForChanges($id, $uid, $note),
                'return_previous' => $this->engine->returnToPreviousApprover($id, $uid, $note),
                'reject'  => $this->engine->reject($id, $uid, $note),
                default   => throw new WorkflowException('Choose approve, return for changes or reject.'),
            };
        }, ['approve' => 'Approved.', 'return' => 'Returned to the submitter for changes.', 'return_previous' => 'Returned to the previous approver.', 'reject' => 'Invoice rejected.'][$decision] ?? 'Done.');
    }

    public function claim(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->claim($id, (int) auth()->id()), 'You accepted this invoice. It is now yours to action.');
    }

    public function release(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->release($id, (int) auth()->id(), (string) $this->request->getPost('note')), 'Released back to the team.');
    }

    // ------------------------------------------------------------------ helpers

    private function guarded(int $id, callable $do, string $success): RedirectResponse
    {
        $this->visible($id);

        try {
            $do();
        } catch (WorkflowException $e) {
            return redirect()->to('invoices/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('invoices/' . $id)->with('message', $success);
    }

    /**
     * After saving details: "save" stays on the edit page, "submit"/"resubmit" try the transition and
     * come back to the form with the reasons when it is not ready yet (the draft is kept).
     */
    private function afterSave(int $id, int $uid, string $action): RedirectResponse
    {
        if ($action === 'submit' || $action === 'resubmit') {
            try {
                $action === 'resubmit'
                    ? $this->engine->resubmit($id, $uid, (string) $this->request->getPost('note'))
                    : $this->engine->submit($id, $uid);
            } catch (WorkflowException $e) {
                return redirect()->to('invoices/' . $id . '/edit')->with('error', $e->getMessage());
            }

            return redirect()->to('invoices/' . $id)->with('message', 'Invoice submitted for approval.');
        }

        return redirect()->to('invoices/' . $id)->with('message', 'Saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $in                   = (array) $this->request->getPost(self::FIELDS);
        $in['extra_approval'] = $this->request->getPost('extra_approval') ? 1 : 0;

        return $in;
    }

    /**
     * Company, invoice type, invoice number, both dates, amount and currency must be filled in whenever the form is
     * saved (also as a draft). Supplier and the document are still only required to submit, but a supplier that
     * is given must be one from the managers' list (an invoice may keep the supplier it already has).
     *
     * @param array<string, mixed> $in
     *
     * @throws WorkflowException listing everything that is missing
     */
    private function assertRequiredDetails(array $in, string $currentSupplier = ''): void
    {
        $text   = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
        $isDate = static fn (string $v): bool => ($t = date_create_from_format('Y-m-d', $v)) !== false && $t->format('Y-m-d') === $v;
        $p      = [];

        if ((int) $text('company_id') <= 0) {
            $p[] = 'Company is required.';
        }
        if ((int) $text('invoice_type_id') <= 0) {
            $p[] = 'Invoice type is required.';
        }
        if ($text('invoice_number') === '') {
            $p[] = 'Invoice number is required.';
        }
        foreach (['invoice_date' => 'Invoice date', 'due_date' => 'Due date'] as $k => $label) {
            if (! $isDate($text($k))) {
                $p[] = $label . ' is required.';
            }
        }
        if ($isDate($text('invoice_date')) && $isDate($text('due_date')) && $text('due_date') < $text('invoice_date')) {
            $p[] = 'The due date cannot be before the invoice date.';
        }
        $amount = str_replace([',', ' '], '', $text('amount'));
        if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            $p[] = 'Amount must be more than zero.';
        }
        if (preg_match('/^[A-Za-z]{3}$/', $text('currency')) !== 1) {
            $p[] = 'Currency is required.';
        }

        $supplier = $text('supplier');
        if ($supplier !== '' && $supplier !== trim($currentSupplier) && ! in_array($supplier, $this->supplierNames(), true)) {
            $p[] = 'Choose a supplier from the list.';
        }

        if ($p !== []) {
            throw new WorkflowException(implode(' ', $p));
        }
    }

    /**
     * The uploaded invoice file, already validated, or null when nothing was chosen. Either a PDF (field "pdf")
     * or one or more photos of a paper invoice (field "photos"), which are combined into a PDF first. For photos
     * "temp" is true: the PDF is a temporary file that storeUpload() removes.
     *
     * @return array{path: string, name: string, temp: bool}|null
     *
     * @throws WorkflowException when the upload failed or is not acceptable
     */
    private function checkedUpload(): ?array
    {
        $pdf    = $this->request->getFile('pdf');
        $hasPdf = $pdf !== null && $pdf->getError() !== UPLOAD_ERR_NO_FILE;

        $photos = [];
        foreach ((array) $this->request->getFileMultiple('photos') as $photo) {
            if ($photo->getError() !== UPLOAD_ERR_NO_FILE) {
                $photos[] = $photo;
            }
        }

        if ($hasPdf && $photos !== []) {
            throw new WorkflowException('Attach either a PDF or photos, not both.');
        }

        $files = new InvoiceFiles();
        if ($photos !== []) {
            foreach ($photos as $photo) {
                if (! $photo->isValid()) {
                    throw new WorkflowException('A photo could not be uploaded: ' . $photo->getErrorString() . '.');
                }
            }
            $tmp = (new ImagePdfBuilder())->build(array_map(static fn (UploadedFile $f): string => $f->getTempName(), $photos));

            try {
                $files->validate($tmp, 'invoice-photo.pdf');
            } catch (WorkflowException $e) {
                @unlink($tmp);

                throw $e;
            }

            return ['path' => $tmp, 'name' => 'invoice-photo.pdf', 'temp' => true];
        }

        if (! $hasPdf) {
            return null;
        }
        if (! $pdf->isValid()) {
            throw new WorkflowException('The file could not be uploaded: ' . $pdf->getErrorString() . '.');
        }

        $files->validate($pdf->getTempName(), $pdf->getClientName(), $pdf->getSize());

        return ['path' => $pdf->getTempName(), 'name' => $pdf->getClientName(), 'temp' => false];
    }

    /**
     * @param array{path: string, name: string, temp: bool}|null $upload
     */
    private function storeUpload(int $invoiceId, int $uid, ?array $upload): void
    {
        if ($upload !== null) {
            (new InvoiceFiles())->store($invoiceId, $uid, $upload['path'], $upload['name']);
        }
    }

    /**
     * @param array{path: string, name: string, temp: bool}|null $upload
     */
    private function discardUpload(?array $upload): void
    {
        if ($upload !== null && $upload['temp']) {
            @unlink($upload['path']);
        }
    }

    /**
     * Names of the active suppliers from the list managers maintain (Admin > Suppliers).
     *
     * @return list<string>
     */
    private function supplierNames(): array
    {
        return array_column($this->supplierOptions(), 'name');
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function supplierOptions(): array
    {
        return service('suppliers')->options();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function submitCompanies(int $uid): array
    {
        return db_connect()->table('companies c')->select('c.id, c.code, c.name')
            ->join('user_companies uc', 'uc.company_id = c.id')
            ->where(['uc.user_id' => $uid, 'uc.can_submit' => 1, 'c.active' => 1])
            ->orderBy('c.code')->get()->getResultArray();
    }

    /**
     * The invoice with names joined, or a 404 when the user may not see its company (we do not reveal
     * that it exists).
     *
     * @return array<string, mixed>
     */
    private function visible(int $id): array
    {
        $inv = db_connect()->table('inv_invoices i')
            ->select('i.*, c.code AS company_code, c.name AS company_name, t.name AS type_name, u.display_name AS submitter_name')
            ->join('companies c', 'c.id = i.company_id')->join('inv_invoice_types t', 't.id = i.invoice_type_id')
            ->join('inv_users u', 'u.id = i.submitted_by', 'left')
            ->where('i.id', $id)->get()->getRowArray();

        if ($inv === null || ! $this->scope->canViewCompany((int) auth()->id(), (int) $inv['company_id'])) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $inv;
    }
}
