<?php

declare(strict_types=1);

namespace Tests\Receivables;

use Config\Backoffice;
use Tests\Support\Payables\PayablesTestCase;

/**
 * Phase 1b: the receivables registry entry (roles, permissions, per-company rights) and the ar_ schema guards.
 *
 * @internal
 */
final class ReceivablesFoundationTest extends PayablesTestCase
{
    private int $company;
    private int $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('ACME');
        $this->db->table('ar_invoice_types')->insert(['company_id' => $this->company, 'name' => 'Sales', 'active' => 1]);
        $this->type = (int) $this->db->insertID();
    }

    private function makeArInvoice(array $over = []): int
    {
        $by = $this->makeUserNamed('Sue Raiser');
        $this->db->table('ar_invoices')->insert($over + [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'submitted_by' => $by,
            'customer' => 'Harbour Foods', 'invoice_number' => 'AR-1', 'amount' => '100.00', 'status' => 'issued',
        ]);

        return (int) $this->db->insertID();
    }

    public function testRolesAndPermissionsAreSeeded(): void
    {
        $roles = config(Backoffice::class)->roleDefinitions();

        $this->assertEqualsCanonicalizing(['receivables.view', 'receivables.reports', 'receivables.collect', 'customers.view'], $roles['receivables_user']['permissions']);
        $this->assertEmpty(array_diff($roles['receivables_user']['permissions'], $roles['receivables_workflow']['permissions']));
        $this->assertContains('receivables.workflow', $roles['receivables_workflow']['permissions']);
        $this->assertContains('customers.manage', $roles['receivables_workflow']['permissions']);
        $this->assertNotContains('receivables.workflow', $roles['receivables_user']['permissions']);
        $this->assertNotContains('tenancy.group_level', $roles['receivables_workflow']['permissions']);

        $this->assertNotNull($this->roleId('receivables_user'));
        $this->assertNotNull($this->roleId('receivables_workflow'));
        $this->assertSame(1, $this->db->table('permissions')->where('key', 'receivables.collect')->countAllResults());
    }

    public function testCompanyRightsAreDeclaredSeparatelyFromInvoices(): void
    {
        $caps = config(Backoffice::class)->companyCapabilities();

        foreach (['submit', 'approve', 'collect'] as $key) {
            $this->assertArrayHasKey("receivables.{$key}", $caps);
            $this->assertNull($caps["receivables.{$key}"]['column'], 'Receivables rights live in user_company_capabilities, not user_companies.');
        }

        $user = $this->makeUserNamed('Cal Collector');
        $this->join($user, $this->company, true, true);
        $scope = service('scope');

        $this->assertTrue($scope->canSubmit($user, $this->company), 'The invoices right is held.');
        $this->assertFalse($scope->can($user, $this->company, 'receivables.collect'), 'Invoice rights grant nothing in receivables.');

        $this->db->table('user_company_capabilities')->insert(['user_id' => $user, 'company_id' => $this->company, 'capability' => 'receivables.collect']);

        $this->assertTrue($scope->can($user, $this->company, 'receivables.collect'));
        $this->assertFalse($scope->can($user, $this->company, 'receivables.approve'));
    }

    public function testPaidCanNeverExceedTheAmount(): void
    {
        $id = $this->makeArInvoice();

        $this->db->table('ar_invoices')->where('id', $id)->update(['amount_paid' => '100.00']);
        $this->assertSame('100.00', $this->db->table('ar_invoices')->where('id', $id)->get()->getRow()->amount_paid);

        foreach (['100.01', '-1.00'] as $bad) {
            try {
                $this->db->table('ar_invoices')->where('id', $id)->update(['amount_paid' => $bad]);
                $this->fail("amount_paid {$bad} should be refused.");
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPaymentsAreNonZeroAndAReceiptIsReversedOnlyOnce(): void
    {
        $id  = $this->makeArInvoice();
        $row = ['invoice_id' => $id, 'received_date' => '2026-10-23', 'recorded_by' => $this->makeUserNamed('Cal Collector')];

        $this->db->table('ar_payments')->insert($row + ['amount' => '40.00']);
        $receipt = (int) $this->db->insertID();
        $this->db->table('ar_payments')->insert($row + ['amount' => '-40.00', 'reverses_id' => $receipt, 'note' => 'Bounced']);

        foreach ([$row + ['amount' => '0.00'], $row + ['amount' => '-40.00', 'reverses_id' => $receipt]] as $bad) {
            try {
                $this->db->table('ar_payments')->insert($bad);
                $this->fail('Expected the database to refuse this receipt.');
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnInvoiceKeepsItsCustomerNameWhenTheCustomerIsRemoved(): void
    {
        $customer = service('customers')->create(['code' => 'C1', 'name' => 'Harbour Foods']);
        $id       = $this->makeArInvoice(['customer_id' => $customer]);

        $this->assertSame(1, (int) service('customers')->all(config(Backoffice::class)->modules['receivables']['customerUsage'])[0]['receivables_invoices']);

        $this->db->table('customers')->where('id', $customer)->delete();

        $row = $this->db->table('ar_invoices')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row['customer_id']);
        $this->assertSame('Harbour Foods', $row['customer']);
    }

    public function testACompanyWithInvoicesCannotBeDeleted(): void
    {
        $id = $this->makeArInvoice();

        try {
            $this->db->table('companies')->where('id', $this->company)->delete();
            $this->fail('Deleting a company that has customer invoices should be refused.');
        } catch (\Throwable) {
            $this->assertSame(1, $this->db->table('ar_invoices')->where('id', $id)->countAllResults());
        }
    }
}
