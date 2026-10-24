<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Tenancy\AccessScope;
use CodeIgniter\Database\BaseConnection;

/**
 * Customer subscriptions: the recurring schedule behind SubscriptionBilling. Creating and changing one needs the
 * per-company receivables.submit right (the same people who may raise a customer invoice); seeing one is decided by
 * AccessScope. Dates are plain Y-m-d strings in the company's calendar.
 */
final class SubscriptionService
{
    public const FREQUENCIES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];

    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public function __construct(private ?BaseConnection $db = null, private ?AccessScope $scope = null, private ?ArAudit $audit = null)
    {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
        $this->audit ??= new ArAudit();
    }

    /**
     * The bill date after $from for a subscription that bills on $billDay of the month. A short month bills on its
     * last day, and the next month goes back to $billDay, so 31 January -> 28 February -> 31 March.
     */
    public static function nextDate(string $from, string $frequency, int $billDay): string
    {
        $months = self::MONTHS[$frequency] ?? 1;
        $d      = date_create_immutable($from);
        $first  = $d->modify('first day of this month')->modify('+' . $months . ' months');
        $day    = min($billDay, (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $in company_id, invoice_type_id, customer_id, customer, description, amount, currency,
     *                                 frequency, next_bill_date, end_date, payment_terms_days, notes
     */
    public function create(int $userId, array $in): int
    {
        $row = $this->clean($in);
        $this->assertCanManage($userId, $row['company_id']);
        $this->assertTypeBelongs($row['invoice_type_id'], $row['company_id']);

        $this->db->table('ar_subscriptions')->insert($row + [
            'start_date' => $row['next_bill_date'], 'bill_day' => (int) date('j', strtotime($row['next_bill_date'])),
            'status' => 'active', 'owner_id' => $userId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->audit->log('ar.subscription_created', $userId, 'ar_subscription', $id);

        return $id;
    }

    /**
     * @param array<string, mixed> $in
     */
    public function update(int $id, int $userId, array $in): void
    {
        $sub = $this->find($id);
        $this->assertCanManage($userId, (int) $sub['company_id']);
        if ($sub['status'] === 'ended') {
            throw new ReceivablesException('An ended subscription cannot be changed.');
        }

        $row = $this->clean($in + ['company_id' => $sub['company_id']]);
        if ($row['company_id'] !== (int) $sub['company_id']) {
            $this->assertCanManage($userId, $row['company_id']);
        }
        $this->assertTypeBelongs($row['invoice_type_id'], $row['company_id']);

        $this->db->table('ar_subscriptions')->where('id', $id)->update($row + [
            'bill_day' => (int) date('j', strtotime($row['next_bill_date'])), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit->log('ar.subscription_updated', $userId, 'ar_subscription', $id);
    }

    /** @param 'pause'|'resume'|'end' $action */
    public function setStatus(int $id, int $userId, string $action): void
    {
        $sub = $this->find($id);
        $this->assertCanManage($userId, (int) $sub['company_id']);

        $to = ['pause' => 'paused', 'resume' => 'active', 'end' => 'ended'][$action] ?? throw new ReceivablesException('Unknown action.');
        if ($sub['status'] === 'ended') {
            throw new ReceivablesException('This subscription has already ended.');
        }
        if ($sub['status'] === $to) {
            return;
        }

        $set = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
        if ($to === 'active') {
            // Resuming does not bill the months it was paused: skip forward to the next date still to come.
            $today = service('timezone')->todayFor((int) $sub['company_id']);
            $next  = (string) $sub['next_bill_date'];
            while ($next < $today) {
                $next = self::nextDate($next, (string) $sub['frequency'], (int) $sub['bill_day']);
            }
            $set['next_bill_date'] = $next;
        }

        $this->db->table('ar_subscriptions')->where('id', $id)->update($set);
        $this->audit->log('ar.subscription_' . $to, $userId, 'ar_subscription', $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        return $this->db->table('ar_subscriptions')->where('id', $id)->get()->getRowArray()
            ?? throw new ReceivablesException('Subscription not found.');
    }

    /**
     * The subscription with its company code and type name, or null when it is missing or in a company the user cannot see.
     *
     * @return array<string, mixed>|null
     */
    public function visibleTo(int $userId, int $id): ?array
    {
        $b = $this->db->table('ar_subscriptions s')
            ->select('s.*, c.code AS company_code, t.name AS type_name')
            ->join('companies c', 'c.id = s.company_id')
            ->join('ar_invoice_types t', 't.id = s.invoice_type_id')
            ->where('s.id', $id);
        $this->scope->restrict($b, $userId, 's.company_id');

        return $b->get()->getRowArray();
    }

    /**
     * Draft invoices already raised for the subscription, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function invoices(int $id): array
    {
        return $this->db->table('ar_subscription_invoices x')
            ->select('x.bill_date, i.id, i.invoice_number, i.status, i.amount, i.currency, i.due_date, i.amount_paid')
            ->join('ar_invoices i', 'i.id = x.invoice_id')
            ->where('x.subscription_id', $id)->orderBy('x.bill_date', 'DESC')->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed> the columns to store
     */
    private function clean(array $in): array
    {
        $text = static fn (mixed $v, int $max): string => mb_substr(trim((string) $v), 0, $max);
        $date = static fn (mixed $v): ?string => ($v = trim((string) $v)) !== '' && ($t = date_create_from_format('Y-m-d', $v)) !== false && $t->format('Y-m-d') === $v ? $v : null;
        $p    = [];

        $customers = service('customers');
        $listed    = ! empty($in['customer_id']) ? $customers->find((int) $in['customer_id']) : null;
        $name      = $listed !== null ? (string) $listed['name'] : $text($in['customer'] ?? '', 200);
        $listed  ??= $name !== '' ? $customers->findByName($name) : null;
        if ($name === '') {
            $p[] = 'Customer is required.';
        }
        if ($listed !== null && (int) $listed['active'] !== 1 && (int) $listed['id'] !== (int) ($in['current_customer_id'] ?? 0)) {
            $p[] = 'That customer is switched off. Choose another, or ask an administrator to switch it on.';
        }

        $description = $text($in['description'] ?? '', 200);
        if ($description === '') {
            $p[] = 'Say what the customer subscribes to.';
        }

        $amount = str_replace([',', ' '], '', trim((string) ($in['amount'] ?? '')));
        if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            $p[] = 'Amount must be more than zero.';
        }

        $currency = strtoupper(trim((string) ($in['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $p[] = 'Currency is required.';
        }

        $frequency = (string) ($in['frequency'] ?? '');
        if (! isset(self::FREQUENCIES[$frequency])) {
            $p[] = 'Choose how often it is billed.';
        }

        $next = $date($in['next_bill_date'] ?? '');
        if ($next === null) {
            $p[] = 'The next bill date is required.';
        }
        $end = $date($in['end_date'] ?? '');
        if (trim((string) ($in['end_date'] ?? '')) !== '' && $end === null) {
            $p[] = 'The end date is not a valid date.';
        }
        if ($end !== null && $next !== null && $end < $next) {
            $p[] = 'The end date cannot be before the next bill date.';
        }

        $terms = (int) ($in['payment_terms_days'] ?? 30);
        if ($terms < 0 || $terms > 365) {
            $p[] = 'Payment terms must be between 0 and 365 days.';
        }

        if ((int) ($in['company_id'] ?? 0) <= 0) {
            $p[] = 'Company is required.';
        }
        if ((int) ($in['invoice_type_id'] ?? 0) <= 0) {
            $p[] = 'Invoice type is required.';
        }

        if ($p !== []) {
            throw new ReceivablesException(implode(' ', $p));
        }

        return [
            'company_id' => (int) $in['company_id'], 'invoice_type_id' => (int) $in['invoice_type_id'],
            'customer_id' => $listed !== null ? (int) $listed['id'] : null, 'customer' => $name, 'description' => $description,
            'amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency, 'frequency' => $frequency,
            'next_bill_date' => $next, 'end_date' => $end, 'payment_terms_days' => $terms,
            'notes' => ($n = $text($in['notes'] ?? '', 5000)) !== '' ? $n : null,
        ];
    }

    private function assertCanManage(int $userId, int $companyId): void
    {
        if (! $this->scope->can($userId, $companyId, 'receivables.submit')) {
            throw new ReceivablesException('You have not been given permission to manage customer subscriptions for that company.');
        }
    }

    private function assertTypeBelongs(int $typeId, int $companyId): void
    {
        if ($this->db->table('ar_invoice_types')->where(['id' => $typeId, 'company_id' => $companyId, 'active' => 1])->countAllResults() === 0) {
            throw new ReceivablesException('Choose an invoice type that belongs to the company.');
        }
    }
}
