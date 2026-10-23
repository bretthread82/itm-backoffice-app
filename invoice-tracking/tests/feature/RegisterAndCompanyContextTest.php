<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdminAccess;
use App\Services\WorkflowEngine;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class RegisterAndCompanyContextTest extends FeatureTestCase
{
    private int $acme;
    private int $beta;
    private int $outside;
    private User $multi;
    private User $solo;
    private User $gary;

    protected function setUp(): void
    {
        parent::setUp();
        $admin       = $this->shieldUser('admin@example.test', 'Admin', true);
        $this->multi = $this->shieldUser('multi@example.test', 'Mia Multi');
        $this->solo  = $this->shieldUser('solo@example.test', 'Sol Solo');
        $this->gary  = $this->shieldUser('gary@example.test', 'Gary Group');

        $this->acme    = $this->makeCompany('ACME');
        $this->beta    = $this->makeCompany('BETA');
        $this->outside = $this->makeCompany('ZED');
        $this->join((int) $this->multi->id, $this->acme, true);
        $this->join((int) $this->multi->id, $this->beta, true);
        $this->join((int) $this->solo->id, $this->acme, true);
        (new AdminAccess($this->db))->grantGroupLevel((int) $admin->id, (int) $this->gary->id);

        $zed = $this->shieldUser("zed@example.test", "Zed Zed");
        $this->join((int) $zed->id, $this->outside, true);
        foreach ([[$this->acme, "INV-ACME", "100.00", $this->multi], [$this->beta, "INV-BETA", "900.00", $this->multi], [$this->outside, "INV-ZED", "50.00", $zed]] as [$company, $no, $amount, $sub]) {
            $type = $this->makeRoute($company, [
                ['type' => 'submit', 'user' => (int) $sub->id, 'role' => 'Clerk', 'label' => 'Sub'],
                ['type' => 'approve', 'user' => (int) $this->gary->id, 'role' => 'Boss', 'label' => 'Gary'],
            ])['type'];
            (new WorkflowEngine($this->db))->createDraft((int) $sub->id, [
                'company_id' => $company, 'invoice_type_id' => $type, 'supplier' => 'Supplier ' . $no,
                'invoice_number' => $no, 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-15', 'amount' => $amount,
            ]);
        }
    }

    private function raw(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    public function testActiveCompanyNarrowsTheRegister(): void
    {
        session();
        $page = $this->actingAs($this->multi)->withSession(['active_company' => $this->beta])->get('invoices');
        $page->assertSee('INV-BETA');
        $page->assertDontSee('INV-ACME');
    }

    public function testCannotActivateACompanyOutsideTheUsersAccess(): void
    {
        $res = $this->actingAs($this->multi)->post('company-context', ['company' => (string) $this->outside]);
        $res->assertRedirect();
        $this->assertNull(session()->get('active_company'));
    }

    public function testAStoredCompanyTheUserCannotSeeIsIgnored(): void
    {
        session();
        $page = $this->actingAs($this->multi)->withSession(['active_company' => $this->outside])->get('invoices');
        $page->assertSee('INV-ACME');
        $page->assertDontSee('INV-ZED');
    }

    public function testSingleCompanyUsersGetNoSelector(): void
    {
        $solo  = $this->raw($this->actingAs($this->solo)->get('invoices'));
        $multi = $this->raw($this->actingAs($this->multi)->get('invoices'));

        $this->assertStringNotContainsString('company-context', $solo);
        $this->assertStringContainsString('company-context', $multi);
        $this->assertStringContainsString('Maloney Group – All Entities', $this->raw($this->actingAs($this->gary)->get('invoices')));
    }

    public function testExportIsLimitedToWhatTheUserMaySee(): void
    {
        $csv = $this->raw($this->actingAs($this->multi)->get('invoices/export.csv'));

        $this->assertStringContainsString('INV-ACME', $csv);
        $this->assertStringContainsString('INV-BETA', $csv);
        $this->assertStringNotContainsString('INV-ZED', $csv);
        $this->assertStringContainsString('INV-ZED', $this->raw($this->actingAs($this->gary)->get('invoices/export.csv')));
    }

    public function testSortAndAmountFiltersWorkAndUnknownSortIsIgnored(): void
    {
        $asc = $this->raw($this->actingAs($this->multi)->get('invoices', ['sort' => 'amount', 'dir' => 'asc']));
        $this->assertLessThan(strpos($asc, 'INV-BETA'), strpos($asc, 'INV-ACME'));

        $min = $this->actingAs($this->multi)->get('invoices', ['min' => '500']);
        $min->assertSee('INV-BETA');
        $min->assertDontSee('INV-ACME');

        $this->actingAs($this->multi)->get('invoices', ['sort' => 'i.id; DROP TABLE invoices'])->assertStatus(200);
    }
}
