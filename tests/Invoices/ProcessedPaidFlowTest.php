<?php

declare(strict_types=1);

namespace Tests\Invoices;

use App\Core\Invoices\FilePurger;
use App\Core\Invoices\InvoiceFiles;
use App\Core\Invoices\WorkflowEngine;
use App\Core\Invoices\WorkflowException;
use Config\Services;
use Tests\Support\Invoices\InvoiceTestCase;

/**
 * Processed and paid are separate stages: the process step marks the invoice processed (and files it), a later pay
 * step records the payment and marks it paid.
 *
 * @internal
 */
final class ProcessedPaidFlowTest extends InvoiceTestCase
{
    private const PAID = ['payment_reference' => 'EFT-7', 'payment_date' => '2026-10-12'];

    private WorkflowEngine $engine;
    private int $company;
    private int $submitter;
    private int $approver;
    private int $processor;
    private int $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new WorkflowEngine($this->db);

        $this->company   = $this->makeCompany('ACME');
        $this->submitter = $this->makeUserWithEmail('Sue Submitter', 'sue@example.test');
        $this->approver  = $this->makeUserWithEmail('Alan Approver', 'alan@example.test');
        $this->processor = $this->makeUserWithEmail('Pat Processor', 'pat@example.test');
        $this->payer     = $this->makeUserWithEmail('Penny Payer', 'penny@example.test');
        $this->join($this->submitter, $this->company, true);
        foreach ([$this->approver, $this->processor, $this->payer] as $u) {
            $this->join($u, $this->company);
        }
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
                parent::tearDown();
    }

    /** @param list<string> $types step types after the submit step */
    private function submitted(array $types, string $typeName = 'Standard'): int
    {
        $who   = ['approve' => $this->approver, 'process' => $this->processor, 'pay' => $this->payer];
        $steps = [['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk', 'label' => 'Sue']];
        foreach ($types as $t) {
            $steps[] = ['type' => $t, 'user' => $who[$t], 'role' => ucfirst($t), 'label' => ucfirst($t) . ' person'];
        }
        $type = $this->makeRoute($this->company, $steps, $typeName)['type'];

        $id = $this->engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $type, 'supplier' => 'Acme', 'invoice_number' => 'INV-' . $typeName,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '100.00', 'currency' => 'usd',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'inv.pdf');
        $this->engine->submit($id, $this->submitter);

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->db->table('inv_invoices')->where('id', $id)->get()->getRowArray();
    }

    public function testProcessThenPayAreSeparateStages(): void
    {
        $id = $this->submitted(['approve', 'process', 'pay']);
        $this->engine->approve($id, $this->approver);
        $this->assertSame('approved', $this->row($id)['status'], 'at the process step');

        // Processing needs no payment details and does not finish the invoice.
        $this->engine->approve($id, $this->processor);
        $r = $this->row($id);
        $this->assertSame('processed', $r['status']);
        $this->assertSame(4, (int) $r['current_step_order'], 'waiting at the pay step');
        $this->assertNotNull($r['processed_at']);
        $this->assertNull($r['paid_at']);
        $this->assertNull($r['completed_at']);
        $this->assertNull($r['payment_reference']);
        $this->assertSame(1, $this->db->table('notifications')->where(['user_id' => $this->submitter, 'type' => 'invoice.processed'])->countAllResults());

        // Only the payer can pay, and only with payment details.
        try {
            $this->engine->approve($id, $this->processor, null, self::PAID);
            $this->fail('The process person must not be able to pay.');
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->engine->approve($id, $this->payer);
            $this->fail('Payment details are required.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('payment reference', $e->getMessage());
        }
        $this->assertSame('processed', $this->row($id)['status']);

        $this->engine->approve($id, $this->payer, null, self::PAID);
        $r = $this->row($id);
        $this->assertSame('paid', $r['status']);
        $this->assertSame('EFT-7', $r['payment_reference']);
        $this->assertSame('2026-10-12', $r['payment_date']);
        $this->assertNotNull($r['paid_at']);
        $this->assertNotNull($r['completed_at']);
        $this->assertNull($r['current_step_order']);
        $this->assertSame(1, $this->db->table('notifications')->where(['user_id' => $this->submitter, 'type' => 'invoice.paid'])->countAllResults());
    }

    public function testARouteWithoutAPayStepEndsAtProcessed(): void
    {
        $id = $this->submitted(['approve', 'process'], 'NoPay');
        $this->engine->approve($id, $this->approver);
        $this->engine->approve($id, $this->processor);

        $r = $this->row($id);
        $this->assertSame('processed', $r['status']);
        $this->assertNull($r['current_step_order']);
        $this->assertNotNull($r['completed_at']);
        $this->assertNull($r['paid_at']);
    }

    public function testARouteOfApprovalsOnlyIsProcessedAtTheEndAndFiled(): void
    {
        $id = $this->submitted(['approve'], 'ApprovalsOnly');
        $this->engine->approve($id, $this->approver);

        $r = $this->row($id);
        $this->assertSame('processed', $r['status']);
        $this->assertNotNull($r['processed_at']);
    }

    public function testAnInvoiceWaitingToBePaidIsStillOutstanding(): void
    {
        $id = $this->submitted(['process', 'pay'], 'Waiting');
        $this->engine->approve($id, $this->processor);

        $this->assertTrue(\App\Core\Invoices\ReportService::isOutstanding($this->row($id)));

        $this->engine->approve($id, $this->payer, null, self::PAID);
        $this->assertFalse(\App\Core\Invoices\ReportService::isOutstanding($this->row($id)));
    }
}
