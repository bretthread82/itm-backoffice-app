<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\AnalyticsLoader;
use App\Services\InvoiceFiles;
use App\Services\WorkflowEngine;
use Config\Services;
use Tests\Support\AnalyticsTestCase;
use Tests\Support\FakeMailTransport;

/**
 * Uses invoicing_test (app) and invoicing_test_analytics (analytics); both are wiped by the suite.
 *
 * @internal
 */
final class AnalyticsLoaderTest extends AnalyticsTestCase
{
    private WorkflowEngine $engine;
    private AnalyticsLoader $loader;
    private int $company;
    private int $submitter;
    private int $approver;
    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('mailer', new FakeMailTransport());
        $this->engine = new WorkflowEngine($this->db);
        $this->loader = new AnalyticsLoader($this->db, $this->analytics);

        $this->company   = $this->makeCompany('ACME');
        $this->submitter = $this->makeUserWithEmail('Sue Submitter', 'sue@example.test');
        $this->approver  = $this->makeUserWithEmail('Alan Approver', 'alan@example.test');
        $this->join($this->submitter, $this->company, true);
        $this->join($this->approver, $this->company);
        $this->type = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk', 'label' => 'Sue'],
            ['type' => 'approve', 'user' => $this->approver, 'role' => 'Manager', 'label' => 'Alan', 'sla' => 2],
        ])['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    private function submitted(string $supplier = 'Acme', string $currency = 'usd', string $amount = '100.00'): int
    {
        $id = $this->engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => $supplier, 'invoice_number' => 'INV-' . random_int(1, 99999),
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => $amount, 'currency' => $currency,
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'inv.pdf');
        $this->engine->submit($id, $this->submitter);

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function fact(int $id): ?array
    {
        return $this->analytics->table('fact_invoice')->where('invoice_id', $id)->get()->getRowArray();
    }

    public function testLoadsAFinishedInvoiceWithItsStepVisits(): void
    {
        $id = $this->submitted();
        $this->engine->approve($id, $this->approver, 'Looks fine');

        $r = $this->loader->run();
        $this->assertSame(1, $r['rebuilt']);

        $f = $this->fact($id);
        $this->assertSame('processed', $f['status']);
        $this->assertSame($this->company, $f['company_id']);
        $this->assertSame('USD', $f['currency']);
        $this->assertSame(0, $f['outstanding']);
        $steps = $this->analytics->table('fact_step')->where('invoice_id', $id)->get()->getResultArray();
        $this->assertCount(1, $steps);
        $this->assertSame('approved', $steps[0]['outcome']);
        $this->assertSame('Alan Approver', $steps[0]['acted_by_name']);
        $this->assertSame(2, $steps[0]['sla_days']);
    }

    public function testRunningTwiceChangesNothingAndTheSecondRunIsEmpty(): void
    {
        $this->engine->approve($this->submitted(), $this->approver);
        $this->loader->run();
        $before = [$this->analytics->table('fact_invoice')->countAllResults(), $this->analytics->table('fact_step')->countAllResults()];

        $second = $this->loader->run(false, date('Y-m-d H:i:s', time() + 5));
        $after = [$this->analytics->table('fact_invoice')->countAllResults(), $this->analytics->table('fact_step')->countAllResults()];

        $this->assertSame($before, $after, 'a re-run rebuilds in place and never duplicates rows');
        $this->assertSame($second['touched'], $second['rebuilt']);
    }

    public function testOnlyChangedInvoicesAreRebuiltAfterTheWatermark(): void
    {
        $a = $this->submitted();
        $this->loader->run();
        // updated_at is compared with >= so a same-second write is never missed; that re-reads at most the boundary invoice.
        $this->assertLessThanOrEqual(1, $this->loader->run(true)['touched'], 'nothing new to load');

        $this->engine->approve($a, $this->approver);
        $b = $this->submitted('Other');
        $r = $this->loader->run(true);
        $this->assertSame(2, $r['touched']);
        $this->assertSame(0, $r['rebuilt'], 'dry run writes nothing');
        $this->assertNull($this->fact($b));

        $this->loader->run();
        $this->assertNotNull($this->fact($b));
        $this->assertSame('processed', $this->fact($a)['status']);
    }

    public function testCurrenciesAreKeptSeparate(): void
    {
        $usd = $this->submitted('Acme', 'usd', '100.00');
        $nzd = $this->submitted('Acme', 'nzd', '250.00');
        $this->loader->run();

        $this->assertSame('USD', $this->fact($usd)['currency']);
        $this->assertSame('NZD', $this->fact($nzd)['currency']);
        $this->assertSame('250.00', (string) $this->fact($nzd)['amount']);
    }

    public function testSupplierCodeComesFromTheManagedList(): void
    {
        $this->db->table('suppliers')->insert(['code' => 'ACM001', 'name' => 'Acme Ltd', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $id = $this->submitted('  acme LTD ');
        $this->loader->run();

        $this->assertSame('ACM001', $this->fact($id)['supplier_code']);
    }

    public function testNoteTextIsNeverCopied(): void
    {
        $id = $this->submitted();
        $this->engine->approve($id, $this->approver, 'SECRET-NOTE-TEXT 4242');
        $this->loader->run();

        foreach (['fact_invoice', 'fact_step', 'dim_company', 'dim_invoice_type', 'etl_state'] as $table) {
            $this->assertStringNotContainsString('SECRET-NOTE-TEXT', json_encode($this->analytics->table($table)->get()->getResultArray()), $table);
        }
    }

    public function testDimensionsCarryTheSubGroupPath(): void
    {
        $this->db->table('company_groups')->insert(['name' => 'Group']);
        $root = (int) $this->db->insertID();
        $this->db->table('company_groups')->insert(['name' => 'Division', 'parent_id' => $root]);
        $child = (int) $this->db->insertID();
        $c = $this->makeCompany('SUB', $child);
        $this->loader->run();

        $row = $this->analytics->table('dim_company')->where('company_id', $c)->get()->getRowArray();
        $this->assertSame(",{$root},{$child},", $row['group_path']);
        $this->assertSame($child, $row['group_id']);
        $this->assertSame('Standard', $this->analytics->table('dim_invoice_type')->where('invoice_type_id', $this->type)->get()->getRowArray()['name']);
    }

    public function testDeletedInvoicesAreRemovedFromTheFacts(): void
    {
        $id = $this->submitted();
        $this->loader->run();
        $this->assertNotNull($this->fact($id));

        $this->db->table('invoice_actions')->where('invoice_id', $id)->delete();
        $this->db->table('invoices')->where('id', $id)->delete();
        $r = $this->loader->run();

        $this->assertSame(1, $r['removed']);
        $this->assertNull($this->fact($id));
        $this->assertSame(0, $this->analytics->table('fact_step')->where('invoice_id', $id)->countAllResults());
    }

    public function testRebuildAllRepairsDamagedFacts(): void
    {
        $id = $this->submitted();
        $this->loader->run();
        $this->analytics->table('fact_invoice')->where('invoice_id', $id)->update(['status' => 'paid', 'amount' => 1]);
        $this->analytics->table('fact_step')->emptyTable();

        $r = $this->loader->rebuildAll();

        $this->assertSame(1, $r['rebuilt']);
        $this->assertSame('in_approval', $this->fact($id)['status']);
        $this->assertSame(1, $this->analytics->table('fact_step')->countAllResults());
        $this->assertNotNull($this->loader->lastRun());
    }
}
