<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Tenancy\AccessScope;
use CodeIgniter\Database\BaseConnection;

/**
 * Money received against issued customer invoices. Receipts are append-only rows in ar_payments: a mistake is
 * undone by reversing the receipt (a negative row that points at it, with a mandatory reason), never by editing or
 * deleting it. Each change locks the invoice row, writes the receipt and asks the engine to bring the invoice in line,
 * all in one transaction, so two people recording at once cannot overpay an invoice.
 * Needs the per-company right receivables.collect; there is no group-level override for money.
 */
final class PaymentService
{
    /** Offered on the form; the column is free text so more can be added without a migration. */
    public const METHODS = ['bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'cash' => 'Cash', 'other' => 'Other'];

    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?ReceivablesEngine $engine = null,
        private ?ArNotifier $notifier = null,
        private ?ArAudit $audit = null,
    ) {
        $this->db       ??= db_connect();
        $this->scope    ??= new AccessScope($this->db);
        $this->engine   ??= new ReceivablesEngine($this->db, $this->scope);
        $this->notifier ??= new ArNotifier($this->db);
        $this->audit    ??= new ArAudit();
    }

    /**
     * @param array{amount?: mixed, received_date?: mixed, reference?: mixed, method?: mixed, note?: mixed} $in
     *
     * @return int the new receipt's id
     *
     * @throws ReceivablesException
     */
    public function record(int $invoiceId, int $userId, array $in): int
    {
        $this->assertActive($userId);

        $this->db->transBegin();
        try {
            $inv = $this->lock($invoiceId);
            $this->assertCanCollect($userId, (int) $inv['company_id']);

            if (! in_array($inv['status'], ReceivablesEngine::COLLECTING, true)) {
                throw new ReceivablesException($inv['status'] === 'paid' ? 'This invoice is already paid in full.' : 'Payments can only be recorded on an issued invoice.');
            }

            $cents = $this->amountCents($in['amount'] ?? '');
            $left  = ReceivablesEngine::cents((string) $inv['amount']) - ReceivablesEngine::cents((string) $inv['amount_paid']);
            if ($cents > $left) {
                throw new ReceivablesException('That is more than is still owed (' . $this->money($left, (string) $inv['currency']) . ').');
            }

            $date = $this->receivedDate($in['received_date'] ?? '', (int) $inv['company_id']);
            $this->db->table('ar_payments')->insert([
                'invoice_id' => $invoiceId, 'amount' => number_format($cents / 100, 2, '.', ''), 'received_date' => $date,
                'reference'  => $this->text($in['reference'] ?? '', 100), 'method' => $this->method($in['method'] ?? ''),
                'note'       => $this->text($in['note'] ?? '', 255), 'recorded_by' => $userId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $paymentId = (int) $this->db->insertID();

            $result = $this->engine->refreshCollection($invoiceId);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof ReceivablesException ? $e : new ReceivablesException('Could not record the payment: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('ar.payment_recorded', $userId, 'ar_invoice', $invoiceId, ['payment' => $paymentId, 'amount' => number_format($cents / 100, 2, '.', ''), 'status' => $result['to']]);
        if ($result['to'] === 'paid') {
            $this->audit->log('ar.paid', $userId, 'ar_invoice', $invoiceId);
        }

        $ctx = [
            'actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'amount' => $this->money($cents, (string) $inv['currency']),
            'outstanding' => $this->money(ReceivablesEngine::cents($result['outstanding']), (string) $inv['currency']),
        ];
        $this->notifier->dispatch($result['to'] === 'paid' ? 'ar.paid' : 'ar.payment_received', $invoiceId, [(int) $inv['submitted_by']], $ctx);

        return $paymentId;
    }

    /**
     * Undoes a receipt that was recorded in error or bounced. The invoice may drop back to part_paid or issued.
     *
     * @throws ReceivablesException
     */
    public function reverse(int $paymentId, int $userId, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ReceivablesException('Give a reason for reversing this payment.');
        }
        $this->assertActive($userId);

        $this->db->transBegin();
        try {
            $payment = $this->db->table('ar_payments')->where('id', $paymentId)->get()->getRowArray()
                ?? throw new ReceivablesException('Payment not found.');
            $inv = $this->lock((int) $payment['invoice_id']);
            $this->assertCanCollect($userId, (int) $inv['company_id']);

            if (ReceivablesEngine::cents((string) $payment['amount']) <= 0) {
                throw new ReceivablesException('A reversal cannot be reversed. Record the payment again instead.');
            }
            if ($this->db->table('ar_payments')->where('reverses_id', $paymentId)->countAllResults() > 0) {
                throw new ReceivablesException('This payment has already been reversed.');
            }
            if (! in_array($inv['status'], [...ReceivablesEngine::COLLECTING, 'paid'], true)) {
                throw new ReceivablesException('Payments can only be reversed on an issued invoice.');
            }

            $this->db->table('ar_payments')->insert([
                'invoice_id' => (int) $payment['invoice_id'], 'amount' => number_format(-ReceivablesEngine::cents((string) $payment['amount']) / 100, 2, '.', ''),
                'received_date' => service('timezone')->todayFor((int) $inv['company_id']), 'reference' => $payment['reference'], 'method' => $payment['method'],
                'reverses_id' => $paymentId, 'note' => mb_substr($reason, 0, 255), 'recorded_by' => $userId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $reversalId = (int) $this->db->insertID();

            $result = $this->engine->refreshCollection((int) $payment['invoice_id']);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof ReceivablesException ? $e : new ReceivablesException('Could not reverse the payment: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('ar.payment_reversed', $userId, 'ar_invoice', (int) $payment['invoice_id'], ['payment' => $paymentId, 'reversal' => $reversalId, 'status' => $result['to']]);

        $this->notifier->dispatch('ar.payment_reversed', (int) $payment['invoice_id'], [(int) $inv['submitted_by']], [
            'actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'note' => $reason,
            'amount' => $this->money(ReceivablesEngine::cents((string) $payment['amount']), (string) $inv['currency']),
            'outstanding' => $this->money(ReceivablesEngine::cents($result['outstanding']), (string) $inv['currency']),
        ]);

        return $reversalId;
    }

    /**
     * An invoice's receipts, oldest first, each marked with whether it has been reversed.
     *
     * @return list<array<string, mixed>>
     */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->query(
            'SELECT p.*, u.display_name AS recorded_by_name,
                    EXISTS (SELECT 1 FROM ar_payments r WHERE r.reverses_id = p.id) AS reversed
             FROM ar_payments p LEFT JOIN inv_users u ON u.id = p.recorded_by
             WHERE p.invoice_id = ? ORDER BY p.id',
            [$invoiceId],
        )->getResultArray();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function lock(int $invoiceId): array
    {
        $row = $this->db->DBDriver === 'MySQLi'
            ? $this->db->query('SELECT * FROM ar_invoices WHERE id = ? FOR UPDATE', [$invoiceId])->getRowArray()
            : $this->db->table('ar_invoices')->where('id', $invoiceId)->get()->getRowArray();

        return $row ?? throw new ReceivablesException('Invoice not found.');
    }

    private function assertActive(int $userId): void
    {
        if ($this->db->table('inv_users')->where('id', $userId)->where('active', 1)->countAllResults() === 0) {
            throw new ReceivablesException('You cannot record payments.');
        }
    }

    private function assertCanCollect(int $userId, int $companyId): void
    {
        $active = $this->db->table('companies')->where(['id' => $companyId, 'active' => 1])->countAllResults() > 0;

        if (! $active || ! $this->scope->can($userId, $companyId, 'receivables.collect')) {
            throw new ReceivablesException('You cannot record payments for that company.');
        }
    }

    private function amountCents(mixed $raw): int
    {
        $raw = str_replace([',', ' '], '', trim((string) $raw));

        if ($raw === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
            throw new ReceivablesException('Enter the amount received as a number above zero, with at most two decimals.');
        }

        return ReceivablesEngine::cents($raw);
    }

    private function receivedDate(mixed $raw, int $companyId): string
    {
        $raw = trim((string) $raw);
        $d   = $raw !== '' ? date_create_from_format('Y-m-d', $raw) : false;

        if ($d === false || $d->format('Y-m-d') !== $raw) {
            throw new ReceivablesException('Enter the date the payment was received.');
        }
        if ($raw > service('timezone')->todayFor($companyId)) {
            throw new ReceivablesException('The date received cannot be in the future.');
        }

        return $raw;
    }

    private function method(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }
        if (! isset(self::METHODS[$raw])) {
            throw new ReceivablesException('Choose one of the listed payment methods.');
        }

        return $raw;
    }

    private function text(mixed $raw, int $max): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : mb_substr($raw, 0, $max);
    }

    private function money(int $cents, string $currency): string
    {
        return $currency . ' ' . number_format($cents / 100, 2);
    }

    private function nameOf(int $userId): string
    {
        $u = $this->db->table('inv_users')->select('display_name, username')->where('id', $userId)->get()->getRowArray();

        return (string) (($u['display_name'] ?? '') ?: ($u['username'] ?? 'Someone'));
    }
}
