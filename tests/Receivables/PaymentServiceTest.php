<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\PaymentService;
use App\Core\Receivables\ReceivablesEngine;
use App\Core\Receivables\ReceivablesException;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * Receipts against issued customer invoices: part and full payment, reversal, rights, and the guards that stop
 * an invoice being overpaid.
 *
 * @internal
 */
final class PaymentServiceTest extends ReceivablesTestCase
{
    private PaymentService $payments;
    private int $company;
    private int $raiser;
    private int $collector;
    private int $invoice;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments  = new PaymentService($this->db);
        $this->company   = $this->makeCompany('ACME');
        $this->raiser    = $this->makeUserNamed('Rae Raiser');
        $this->collector = $this->makeUserNamed('Cal Collector');
        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->collector, $this->company, ['collect']);

        $this->invoice = $this->makeIssued($this->company, $this->raiser);
        $this->today   = service('timezone')->todayFor($this->company);
    }

    private function pay(string $amount, array $over = [], ?int $by = null, ?int $invoice = null): int
    {
        return $this->payments->record($invoice ?? $this->invoice, $by ?? $this->collector, $over + ['amount' => $amount, 'received_date' => $this->today]);
    }

    private function failsWith(string $contains, callable $do): void
    {
        try {
            $do();
            $this->fail('Expected a ReceivablesException containing: ' . $contains);
        } catch (ReceivablesException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public function testAFullPaymentMarksTheInvoicePaid(): void
    {
        $this->pay('250.00', ['reference' => 'WIRE-1', 'method' => 'bank_transfer', 'note' => 'Wire']);

        $row = $this->arRow($this->invoice);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('250.00', $row['amount_paid']);
        $this->assertSame($this->today, $row['last_payment_date']);
        $this->assertNotNull($row['paid_at']);
        $this->assertNotNull($row['ended_at']);

        $p = $this->db->table('ar_payments')->where('invoice_id', $this->invoice)->get()->getRowArray();
        $this->assertSame('WIRE-1', $p['reference']);
        $this->assertSame('bank_transfer', $p['method']);
        $this->assertSame($this->collector, (int) $p['recorded_by']);

        $this->assertSame(1, $this->bell($this->raiser, 'ar.paid'));
        $this->assertSame(0, $this->bell($this->raiser, 'ar.payment_received'));
    }

    public function testPartPaymentsAddUpToPaid(): void
    {
        $this->pay('100.00', ['received_date' => date('Y-m-d', strtotime($this->today . ' -2 days'))]);
        $row = $this->arRow($this->invoice);
        $this->assertSame('part_paid', $row['status']);
        $this->assertSame('100.00', $row['amount_paid']);
        $this->assertNull($row['paid_at']);
        $this->assertNull($row['ended_at']);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.payment_received'));

        $this->pay('50.50');
        $this->assertSame('part_paid', $this->arRow($this->invoice)['status']);
        $this->assertSame('150.50', $this->arRow($this->invoice)['amount_paid']);
        $this->assertSame($this->today, $this->arRow($this->invoice)['last_payment_date']);

        $this->pay('99.50');
        $row = $this->arRow($this->invoice);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('250.00', $row['amount_paid']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badReceipts(): iterable
    {
        yield 'more than owed' => [['amount' => '250.01'], 'more than is still owed'];
        yield 'zero' => [['amount' => '0'], 'above zero'];
        yield 'negative' => [['amount' => '-5'], 'above zero'];
        yield 'text' => [['amount' => 'lots'], 'above zero'];
        yield 'three decimals' => [['amount' => '10.005'], 'two decimals'];
        yield 'blank' => [['amount' => ''], 'above zero'];
        yield 'no date' => [['received_date' => ''], 'date the payment was received'];
        yield 'impossible date' => [['received_date' => '2026-02-30'], 'date the payment was received'];
        yield 'future date' => [['received_date' => '2999-01-01'], 'cannot be in the future'];
        yield 'unknown method' => [['method' => 'bitcoin'], 'listed payment methods'];
    }

    /**
     * @dataProvider badReceipts
     *
     * @param array<string, mixed> $over
     */
    public function testBadReceiptsAreRefusedAndNothingIsWritten(array $over, string $message): void
    {
        $this->failsWith($message, fn () => $this->pay('10.00', $over));

        $this->assertSame(0, $this->db->table('ar_payments')->countAllResults());
        $this->assertSame('issued', $this->arRow($this->invoice)['status']);
        $this->assertSame('0.00', $this->arRow($this->invoice)['amount_paid']);
    }

    public function testOverpayingAfterAPartPaymentIsRefused(): void
    {
        $this->pay('200.00');

        $this->failsWith('more than is still owed (USD 50.00)', fn () => $this->pay('50.01'));
        $this->pay('50.00');
        $this->assertSame('paid', $this->arRow($this->invoice)['status']);
        $this->failsWith('already paid in full', fn () => $this->pay('0.01'));
    }

    public function testOnlyIssuedInvoicesCanBePaid(): void
    {
        foreach (['draft', 'in_approval', 'approved', 'returned', 'rejected', 'cancelled'] as $status) {
            $id = $this->makeIssued($this->company, $this->raiser, ['status' => $status]);

            $this->failsWith('issued invoice', fn () => $this->pay('10.00', [], null, $id));
        }
    }

    public function testRecordingNeedsTheCollectRightForThatCompany(): void
    {
        $approver = $this->makeUserNamed('Alan Approver');
        $this->grant($approver, $this->company, ['approve', 'submit']);
        $payablesUser = $this->makeUserNamed('Pat Payables');
        $this->join($payablesUser, $this->company, true, true);
        $group = $this->makeUserNamed('Gus Group');
        $this->grantGroupLevel($group);
        $other     = $this->makeCompany('BETA');
        $elsewhere = $this->makeUserNamed('Ella Elsewhere');
        $this->grant($elsewhere, $other, ['collect']);

        foreach ([$this->raiser, $approver, $payablesUser, $group, $elsewhere] as $user) {
            $this->failsWith('cannot record payments', fn () => $this->pay('10.00', [], $user));
        }

        $this->assertSame(0, $this->db->table('ar_payments')->countAllResults());
    }

    public function testAnInactiveUserOrCompanyCannotRecord(): void
    {
        $gone = $this->makeUserNamed('Gone Gary', null, false, false);
        $this->grant($gone, $this->company, ['collect']);
        $this->failsWith('cannot record payments', fn () => $this->pay('10.00', [], $gone));

        $this->db->table('companies')->where('id', $this->company)->update(['active' => 0]);
        $this->failsWith('cannot record payments', fn () => $this->pay('10.00'));
    }

    public function testReversingReopensThePaidInvoiceAndNeedsAReason(): void
    {
        $first  = $this->pay('100.00', ['reference' => 'CHQ-1', 'method' => 'cheque', 'received_date' => date('Y-m-d', strtotime($this->today . ' -3 days'))]);
        $second = $this->pay('150.00');
        $this->assertSame('paid', $this->arRow($this->invoice)['status']);

        $this->failsWith('Give a reason', fn () => $this->payments->reverse($second, $this->collector, '  '));

        $reversal = $this->payments->reverse($second, $this->collector, 'Cheque bounced');
        $row      = $this->arRow($this->invoice);
        $this->assertSame('part_paid', $row['status']);
        $this->assertSame('100.00', $row['amount_paid']);
        $this->assertNull($row['paid_at']);
        $this->assertNull($row['ended_at']);
        $this->assertSame(date('Y-m-d', strtotime($this->today . ' -3 days')), $row['last_payment_date'], 'A reversed receipt no longer counts as the last payment.');

        $rev = $this->db->table('ar_payments')->where('id', $reversal)->get()->getRowArray();
        $this->assertSame('-150.00', $rev['amount']);
        $this->assertSame($second, (int) $rev['reverses_id']);
        $this->assertSame('Cheque bounced', $rev['note']);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.payment_reversed'));

        // Reversing the other receipt takes it back to issued; the amount can then be collected again.
        $this->payments->reverse($first, $this->collector, 'Wrong customer');
        $row = $this->arRow($this->invoice);
        $this->assertSame('issued', $row['status']);
        $this->assertSame('0.00', $row['amount_paid']);
        $this->assertNull($row['last_payment_date']);

        $this->pay('250.00');
        $this->assertSame('paid', $this->arRow($this->invoice)['status']);
    }

    public function testAReceiptIsReversedOnceAndAReversalIsNotReversible(): void
    {
        $id       = $this->pay('50.00');
        $reversal = $this->payments->reverse($id, $this->collector, 'Mistake');

        $this->failsWith('already been reversed', fn () => $this->payments->reverse($id, $this->collector, 'Again'));
        $this->failsWith('cannot be reversed', fn () => $this->payments->reverse($reversal, $this->collector, 'Undo'));
        $this->failsWith('Payment not found', fn () => $this->payments->reverse(999999, $this->collector, 'x'));
    }

    public function testReversalNeedsTheCollectRight(): void
    {
        $id = $this->pay('50.00');

        $this->failsWith('cannot record payments', fn () => $this->payments->reverse($id, $this->raiser, 'Nope'));
        $this->assertSame('50.00', $this->arRow($this->invoice)['amount_paid']);
    }

    public function testReceiptsListShowsReversals(): void
    {
        $a = $this->pay('50.00');
        $this->pay('25.00');
        $this->payments->reverse($a, $this->collector, 'Oops');

        $rows = $this->payments->forInvoice($this->invoice);
        $this->assertSame(['50.00', '25.00', '-50.00'], array_column($rows, 'amount'));
        $this->assertSame([1, 0, 0], array_map('intval', array_column($rows, 'reversed')));
    }

    public function testTheEngineRefusesToRefreshOutsideATransaction(): void
    {
        $this->expectException(\LogicException::class);
        (new ReceivablesEngine($this->db))->refreshCollection($this->invoice);
    }

    public function testEveryMoneyChangeIsAudited(): void
    {
        $id = $this->pay('250.00');
        $this->payments->reverse($id, $this->collector, 'Oops');

        $actions = array_column($this->db->table('audit_log')->where('entity', 'ar_invoice')->where('entity_id', (string) $this->invoice)->get()->getResultArray(), 'action');
        foreach (['ar.payment_recorded', 'ar.paid', 'ar.payment_reversed'] as $a) {
            $this->assertContains($a, $actions);
        }
    }
}
