<?php

declare(strict_types=1);

namespace App\Controllers\Receivables;

use App\Controllers\BaseController;
use App\Core\Receivables\ReceivablesException;
use App\Core\Receivables\SubscriptionBilling;
use App\Core\Receivables\SubscriptionService;
use App\Core\Tenancy\AccessScope;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Customer subscriptions: the register, setting one up, and pausing, resuming or ending it. Thin: the rules live in
 * SubscriptionService and SubscriptionBilling. receivables.view opens the screens; AccessScope decides which
 * subscriptions a user sees, and the per-company receivables.submit right decides who may change them.
 */
class Subscriptions extends BaseController
{
    private const PER_PAGE = 25;

    private const FIELDS = ['company_id', 'invoice_type_id', 'customer_id', 'customer', 'description', 'amount', 'currency', 'frequency', 'next_bill_date', 'end_date', 'payment_terms_days', 'notes'];

    private SubscriptionService $subscriptions;
    private AccessScope $scope;

    public function __construct()
    {
        $this->scope         = new AccessScope();
        $this->subscriptions = new SubscriptionService(null, $this->scope);
    }

    public function index(): string
    {
        $uid  = (int) auth()->id();
        $get  = $this->request->getGet();
        $f    = [
            'q'       => trim((string) ($get['q'] ?? '')),
            'status'  => in_array($get['status'] ?? '', ['active', 'paused', 'ended'], true) ? (string) $get['status'] : '',
            'company' => (int) ($get['company'] ?? 0),
        ];
        $page = max(1, (int) ($get['page'] ?? 0));

        $b = db_connect()->table('ar_subscriptions s')
            ->select('s.*, c.code AS company_code, t.name AS type_name')
            ->join('companies c', 'c.id = s.company_id')
            ->join('ar_invoice_types t', 't.id = s.invoice_type_id');
        $this->scope->restrict($b, $uid, 's.company_id');

        if ($f['q'] !== '') {
            $b->groupStart()->like('s.customer', $f['q'])->orLike('s.description', $f['q'])->groupEnd();
        }
        if ($f['status'] !== '') {
            $b->where('s.status', $f['status']);
        }
        if ($f['company'] > 0) {
            $b->where('s.company_id', $f['company']);
        }
        // Soonest to bill first; paused and ended ones last.
        $b->orderBy("FIELD(s.status, 'active', 'paused', 'ended')", '', false)->orderBy('s.next_bill_date')->orderBy('s.id');

        $total = (clone $b)->countAllResults(false);
        $rows  = $b->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('receivables/subscriptions/index', [
            'title'     => 'Customer subscriptions',
            'rows'      => $rows,
            'companies' => $this->scope->visibleCompanies($uid),
            'f'         => $f,
            'page'      => $page,
            'pages'     => (int) max(1, ceil($total / self::PER_PAGE)),
            'perPage'   => self::PER_PAGE,
            'total'     => $total,
            'canManage' => $this->scope->companiesWith($uid, 'receivables.submit') !== [],
            'myCompany' => array_column($this->scope->companiesWith($uid, 'receivables.submit'), 'id'),
            'leadDays'  => SubscriptionBilling::leadDays(),
            'today'     => service('timezone')->todayFor(),
        ]);
    }

    public function new(): string|RedirectResponse
    {
        $companies = $this->scope->companiesWith((int) auth()->id(), 'receivables.submit');
        if ($companies === []) {
            return redirect()->to('receivables/subscriptions')->with('error', 'You have not been given permission to manage customer subscriptions for any company.');
        }

        return $this->form('New customer subscription', null, [
            'company_id' => count($companies) === 1 ? $companies[0]['id'] : null, 'currency' => setting('InvoiceSettings.defaultCurrency'),
            'frequency' => 'monthly', 'payment_terms_days' => 30,
        ], $companies);
    }

    public function create(): RedirectResponse
    {
        try {
            $id = $this->subscriptions->create((int) auth()->id(), $this->input());
        } catch (ReceivablesException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('receivables/subscriptions/' . $id)->with('message', 'Subscription set up.');
    }

    public function show(int $id): string
    {
        $uid = (int) auth()->id();
        $sub = $this->visible($id);

        return view('receivables/subscriptions/show', [
            'title'     => 'Subscription: ' . $sub['customer'],
            'sub'       => $sub,
            'invoices'  => $this->subscriptions->invoices($id),
            'canManage' => $this->scope->can($uid, (int) $sub['company_id'], 'receivables.submit'),
            'leadDays'  => SubscriptionBilling::leadDays(),
            'today'     => service('timezone')->todayFor((int) $sub['company_id']),
        ]);
    }

    public function edit(int $id): string|RedirectResponse
    {
        $uid = (int) auth()->id();
        $sub = $this->visible($id);

        if (! $this->scope->can($uid, (int) $sub['company_id'], 'receivables.submit') || $sub['status'] === 'ended') {
            return redirect()->to('receivables/subscriptions/' . $id)->with('error', 'This subscription cannot be edited.');
        }

        return $this->form('Edit customer subscription', $id, $sub, $this->scope->companiesWith($uid, 'receivables.submit'));
    }

    public function update(int $id): RedirectResponse
    {
        $this->visible($id);

        try {
            $sub = $this->subscriptions->find($id);
            $this->subscriptions->update($id, (int) auth()->id(), $this->input() + ['current_customer_id' => (int) $sub['customer_id']]);
        } catch (ReceivablesException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('receivables/subscriptions/' . $id)->with('message', 'Saved.');
    }

    public function status(int $id, string $action): RedirectResponse
    {
        $this->visible($id);

        try {
            $this->subscriptions->setStatus($id, (int) auth()->id(), $action);
        } catch (ReceivablesException $e) {
            return redirect()->to('receivables/subscriptions/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('receivables/subscriptions/' . $id)->with('message', ['pause' => 'Subscription paused. No invoices will be raised until you resume it.', 'resume' => 'Subscription resumed.', 'end' => 'Subscription ended.'][$action]);
    }

    /**
     * @param array<string, mixed>       $sub
     * @param list<array<string, mixed>> $companies
     */
    private function form(string $title, ?int $id, array $sub, array $companies): string
    {
        return view('receivables/subscriptions/form', [
            'title'     => $title,
            'sub'       => $sub,
            'id'        => $id,
            'companies' => $companies,
            'customers' => service('customers')->options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return (array) $this->request->getPost(self::FIELDS);
    }

    /**
     * @return array<string, mixed> the subscription, or a 404 when it does not exist or is in a company the user cannot see
     */
    private function visible(int $id): array
    {
        return $this->subscriptions->visibleTo((int) auth()->id(), $id) ?? throw PageNotFoundException::forPageNotFound('Subscription not found.');
    }
}
