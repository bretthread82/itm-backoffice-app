<?php

declare(strict_types=1);

namespace App\Controllers\Receivables;

use App\Controllers\BaseController;
use App\Core\Payables\Csv;
use App\Core\Receivables\ArDocuments;
use App\Core\Receivables\ArFiles;
use App\Core\Receivables\ArQuery;
use App\Core\Receivables\ArTimeline;
use App\Core\Receivables\Collections;
use App\Core\Receivables\PaymentService;
use App\Core\Receivables\ReceivablesEngine;
use App\Core\Receivables\ReceivablesException;
use App\Core\Tenancy\AccessScope;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Customer invoices: the register, raising and editing, the approval and issue workflow, and payments received.
 * Thin: every rule lives in ReceivablesEngine and PaymentService. What a user sees inside is decided per company by
 * AccessScope; the receivables.view permission only opens the screens.
 */
class CustomerInvoices extends BaseController
{
    private const PER_PAGE = 25;

    private const FIELDS = ['company_id', 'invoice_type_id', 'customer_id', 'customer', 'invoice_number', 'invoice_date', 'due_date', 'amount', 'currency', 'customer_po_reference', 'extra_approval'];

    private ReceivablesEngine $engine;
    private AccessScope $scope;

    public function __construct()
    {
        $this->engine = new ReceivablesEngine();
        $this->scope  = new AccessScope();
    }

    // ------------------------------------------------------------------ list

    public function index(): string
    {
        $uid = (int) auth()->id();
        [$builder, $f] = $this->filtered($uid);
        $page = max(1, (int) $this->request->getGet('page'));

        $total    = (clone $builder)->countAllResults(false);
        $summary  = (new ArQuery($this->scope))->summary($uid, $this->request->getGet(), $this->scopedCompany((int) ($this->request->getGet('company') ?? 0)));
        $invoices = $builder->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('receivables/index', [
            'title'     => 'Customer invoices',
            'invoices'  => $invoices,
            'summary'   => $summary,
            'companies' => $this->scope->visibleCompanies($uid),
            'f'         => $f,
            'page'      => $page,
            'pages'     => (int) max(1, ceil($total / self::PER_PAGE)),
            'perPage'   => self::PER_PAGE,
            'total'     => $total,
            'canSubmit' => $this->scope->companiesWith($uid, 'receivables.submit') !== [],
        ]);
    }

    /** CSV of the current filters, limited to what the user may see. */
    public function export(): ResponseInterface
    {
        [$builder] = $this->filtered((int) auth()->id());

        $rows = [];
        foreach ($builder->limit(5000)->get()->getResultArray() as $i) {
            $rows[] = [
                $i['invoice_number'] ?: 'Draft #' . $i['id'], $i['company_code'], $i['customer'], $i['invoice_date'], $i['due_date'],
                $i['amount'] === null ? '' : (float) $i['amount'], (float) $i['amount_paid'], Collections::outstanding($i), $i['currency'],
                ar_status_label((string) $i['status']), ar_days_overdue($i), $i['customer_po_reference'],
            ];
        }
        $csv = Csv::build(['Invoice', 'Company', 'Customer', 'Invoice date', 'Due date', 'Amount', 'Paid', 'Outstanding', 'Currency', 'Status', 'Days overdue', 'Customer PO'], $rows);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="customer-invoices-' . date('Y-m-d') . '.csv"')
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

        return (new ArQuery($this->scope))->build($uid, $get, $this->scopedCompany((int) ($get['company'] ?? 0)));
    }

    // ------------------------------------------------------------------ create

    public function new(): string|RedirectResponse
    {
        $companies = $this->scope->companiesWith((int) auth()->id(), 'receivables.submit');
        if ($companies === []) {
            return redirect()->to('receivables')->with('error', 'You have not been given permission to raise customer invoices for any company.');
        }

        return view('receivables/form', [
            'title'     => 'New customer invoice',
            'invoice'   => ['company_id' => count($companies) === 1 ? $companies[0]['id'] : null, 'currency' => setting('InvoiceSettings.defaultCurrency'), 'extra_approval' => 0],
            'id'        => null,
            'companies' => $companies,
            'customers' => service('customers')->options(),
            'file'      => null,
            'status'    => 'draft',
        ]);
    }

    /**
     * Types a user can raise invoices for in a company, with a preview of the approval route.
     */
    public function types(): ResponseInterface
    {
        $uid       = (int) auth()->id();
        $companyId = (int) $this->request->getGet('company');
        $db        = db_connect();

        if (! in_array($companyId, array_column($this->scope->companiesWith($uid, 'receivables.submit'), 'id'), true)) {
            return $this->response->setStatusCode(403)->setJSON(['types' => []]);
        }

        $types = [];
        foreach ($db->table('ar_invoice_types')->where(['company_id' => $companyId, 'active' => 1])->orderBy('name')->get()->getResultArray() as $t) {
            $default = $db->table('ar_routes')->where(['company_id' => $companyId, 'invoice_type_id' => $t['id'], 'variant' => 'default', 'active' => 1])->get()->getRowArray();
            if ($default === null) {
                continue;
            }
            $steps = $db->table('ar_route_steps s')->select('s.step_type, s.assignee_label, j.name AS role')
                ->join('ar_job_titles j', 'j.id = s.job_title_id', 'left')->where('s.route_id', $default['id'])->orderBy('s.step_order')->get()->getResultArray();
            $variant = $db->table('ar_routes')->where(['company_id' => $companyId, 'invoice_type_id' => $t['id'], 'variant !=' => 'default', 'active' => 1, 'variant_trigger' => 'manual_flag'])->countAllResults() > 0;

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

        try {
            $this->assertRequiredDetails($this->input());
            $this->checkedUpload();                      // a bad file stops everything: no orphan draft
            $id = $this->engine->createDraft($uid, $this->input());
            $this->storeUpload($id, $uid);
        } catch (ReceivablesException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return $this->afterSave($id, $uid, (string) $this->request->getPost('action'));
    }

    // ------------------------------------------------------------------ view

    public function show(int $id): string
    {
        $uid = (int) auth()->id();
        $inv = $this->visible($id);

        $files = new ArFiles();
        $file  = $files->current($id);

        $steps = $this->engine->stepsOf($inv);
        if ($steps === [] && $inv['status'] === 'draft') {
            try {
                $steps = $this->engine->selectRoute($inv)['steps'];
            } catch (ReceivablesException) {
                $steps = [];
            }
        }

        [$timeline, $actedByStep] = (new ArTimeline())->for($id);
        $isSubmitter = (int) $inv['submitted_by'] === $uid;
        $collecting  = in_array($inv['status'], ReceivablesEngine::COLLECTING, true);

        return view('receivables/show', [
            'title'        => 'Customer invoice ' . ($inv['invoice_number'] ?: '#' . $id),
            'inv'          => $inv,
            'file'         => $file,
            'fileExists'   => $file !== null && $files->path($file) !== null,
            'documents'    => (new ArDocuments())->forInvoice($id),
            'canAttach'    => $this->engine->canAttach($id, $uid),
            'steps'        => $steps,
            'acted'        => $actedByStep,
            'timeline'     => $timeline,
            'can'          => $this->engine->canAct($id, $uid),
            'canEdit'      => $isSubmitter && in_array($inv['status'], ReceivablesEngine::EDITABLE, true),
            'isSubmitter'  => $isSubmitter,
            'problems'     => $inv['status'] === 'draft' && $isSubmitter ? $this->engine->problemsForSubmit($id) : [],
            'currentLabel' => ($step = $this->engine->currentStep($inv)) ? $this->engine->label($step) : '',
            'payments'     => (new PaymentService())->forInvoice($id),
            'canCollect'   => ($collecting || $inv['status'] === 'paid') && $this->scope->can($uid, (int) $inv['company_id'], 'receivables.collect'),
            'collecting'   => $collecting,
            'outstanding'  => Collections::outstanding($inv),
            'daysOverdue'  => ar_days_overdue($inv),
            'methods'      => PaymentService::METHODS,
        ]);
    }

    public function file(int $id): ResponseInterface
    {
        $this->visible($id);

        $files = new ArFiles();
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

        if ((int) $inv['submitted_by'] !== $uid || ! in_array($inv['status'], ReceivablesEngine::EDITABLE, true)) {
            return redirect()->to('receivables/' . $id)->with('error', 'This invoice cannot be edited.');
        }

        return view('receivables/form', [
            'title'     => 'Edit customer invoice',
            'invoice'   => $inv,
            'id'        => $id,
            'companies' => $this->scope->companiesWith($uid, 'receivables.submit'),
            'customers' => service('customers')->options(),
            'file'      => (new ArFiles())->current($id),
            'status'    => $inv['status'],
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $uid = (int) auth()->id();
        $this->visible($id);

        try {
            $this->assertRequiredDetails($this->input());
            $this->checkedUpload();                      // a bad file changes nothing
            $this->engine->updateDetails($id, $uid, $this->input());
            $this->storeUpload($id, $uid);
        } catch (ReceivablesException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
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
                throw new ReceivablesException('Choose a file to attach.');
            }
            if (! $file->isValid()) {
                throw new ReceivablesException('The file could not be uploaded: ' . $file->getErrorString() . '.');
            }

            $this->engine->addDocument($id, (int) auth()->id(), $file->getTempName(), $file->getClientName(), (string) $this->request->getPost('note'));
        }, 'Document attached.');
    }

    public function document(int $id, int $docId): ResponseInterface
    {
        $this->visible($id);

        $docs = new ArDocuments();
        $doc  = $docs->find($id, $docId);
        $path = $doc !== null ? $docs->path($doc) : null;
        if ($doc === null || $path === null) {
            throw PageNotFoundException::forPageNotFound('The document is no longer stored on this server.');
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $doc['original_name']) ?: 'document';
        $disp = \App\Core\Payables\InvoiceDocuments::isInline((string) $doc['mime']) && ! $this->request->getGet('download') ? 'inline' : 'attachment';

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
                'approve'         => $this->engine->approve($id, $uid, $note, ['sent_to' => $this->request->getPost('sent_to')]),
                'return'          => $this->engine->returnForChanges($id, $uid, $note),
                'return_previous' => $this->engine->returnToPreviousApprover($id, $uid, $note),
                'reject'          => $this->engine->reject($id, $uid, $note),
                default           => throw new ReceivablesException('Choose approve, return for changes or reject.'),
            };
        }, ['approve' => 'Done.', 'return' => 'Returned to the person who raised it.', 'return_previous' => 'Returned to the previous approver.', 'reject' => 'Invoice rejected.'][$decision] ?? 'Done.');
    }

    public function claim(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->claim($id, (int) auth()->id()), 'You accepted this invoice. It is now yours to action.');
    }

    public function release(int $id): RedirectResponse
    {
        return $this->guarded($id, fn () => $this->engine->release($id, (int) auth()->id(), (string) $this->request->getPost('note')), 'Released back to the team.');
    }

    // ------------------------------------------------------------------ payments received

    public function recordPayment(int $id): RedirectResponse
    {
        $uid = (int) auth()->id();
        $in  = [];
        foreach (['amount', 'received_date', 'reference', 'method', 'note'] as $k) {
            $in[$k] = $this->request->getPost($k);
        }

        return $this->guarded($id, fn () => (new PaymentService())->record($id, $uid, $in), 'Payment recorded.', true);
    }

    public function reversePayment(int $id, int $paymentId): RedirectResponse
    {
        $uid = (int) auth()->id();

        return $this->guarded($id, function () use ($id, $uid, $paymentId): void {
            // The receipt must belong to this invoice, so a crafted URL cannot reverse another invoice's payment.
            if (db_connect()->table('ar_payments')->where(['id' => $paymentId, 'invoice_id' => $id])->countAllResults() === 0) {
                throw new ReceivablesException('Payment not found.');
            }

            (new PaymentService())->reverse($paymentId, $uid, (string) $this->request->getPost('reason'));
        }, 'Payment reversed.');
    }

    // ------------------------------------------------------------------ helpers

    private function guarded(int $id, callable $do, string $success, bool $keepInput = false): RedirectResponse
    {
        $this->visible($id);

        try {
            $do();
        } catch (ReceivablesException $e) {
            $back = redirect()->to('receivables/' . $id)->with('error', $e->getMessage());

            return $keepInput ? $back->withInput() : $back;
        }

        return redirect()->to('receivables/' . $id)->with('message', $success);
    }

    /**
     * After saving details: "save" goes to the invoice, "submit"/"resubmit" try the transition and come back to the
     * form with the reasons when it is not ready yet (the draft is kept).
     */
    private function afterSave(int $id, int $uid, string $action): RedirectResponse
    {
        if ($action === 'submit' || $action === 'resubmit') {
            try {
                $action === 'resubmit'
                    ? $this->engine->resubmit($id, $uid, (string) $this->request->getPost('note'))
                    : $this->engine->submit($id, $uid);
            } catch (ReceivablesException $e) {
                return redirect()->to('receivables/' . $id . '/edit')->with('error', $e->getMessage());
            }

            return redirect()->to('receivables/' . $id)->with('message', 'Invoice submitted for approval.');
        }

        return redirect()->to('receivables/' . $id)->with('message', 'Saved.');
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
     * saved (also as a draft). The customer and the PDF are still only required to submit.
     *
     * @param array<string, mixed> $in
     *
     * @throws ReceivablesException listing everything that is missing
     */
    private function assertRequiredDetails(array $in): void
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

        if ($p !== []) {
            throw new ReceivablesException(implode(' ', $p));
        }
    }

    /**
     * Checks the uploaded invoice PDF (field "pdf") before anything is saved, so a bad file leaves nothing behind.
     * No file chosen is fine: the PDF is only needed to submit.
     *
     * @throws ReceivablesException when the upload failed or is not acceptable
     */
    private function checkedUpload(): void
    {
        $pdf = $this->request->getFile('pdf');
        if ($pdf === null || $pdf->getError() === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if (! $pdf->isValid()) {
            throw new ReceivablesException('The file could not be uploaded: ' . $pdf->getErrorString() . '.');
        }

        (new ArFiles())->validate($pdf->getTempName(), $pdf->getClientName(), $pdf->getSize());
    }

    private function storeUpload(int $invoiceId, int $uid): void
    {
        $pdf = $this->request->getFile('pdf');

        if ($pdf !== null && $pdf->getError() !== UPLOAD_ERR_NO_FILE && $pdf->isValid()) {
            (new ArFiles())->store($invoiceId, $uid, $pdf->getTempName(), $pdf->getClientName());
        }
    }

    /**
     * The invoice with names joined, or a 404 when the user may not see its company (we do not reveal that it exists).
     *
     * @return array<string, mixed>
     */
    private function visible(int $id): array
    {
        return (new ArQuery($this->scope))->find((int) auth()->id(), $id) ?? throw PageNotFoundException::forPageNotFound();
    }
}
