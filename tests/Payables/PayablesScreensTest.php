<?php

declare(strict_types=1);

namespace Tests\Payables;

use App\Core\Payables\InvoiceFiles;
use App\Core\Payables\WorkflowEngine;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Payables\PayablesTestCase;

/**
 * Real requests through the full filter pipeline: every invoices screen renders for someone with access,
 * the permission gate holds, and the register only ever shows what AccessScope allows.
 */
final class PayablesScreensTest extends PayablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $company;
    private int $other;
    private int $admin;
    private int $user;
    private int $invoice;
    private int $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->company = $this->makeCompany('ACME');
        $this->other   = $this->makeCompany('OTHER');
        $this->admin   = $this->makeUserNamed('Ada Admin', 'ada@example.test', true);
        $this->user    = $this->makeUserNamed('Sue Submitter', 'sue@example.test');
        $approver      = $this->makeUserNamed('Alan Approver', 'alan@example.test');

        foreach ([$this->admin, $approver] as $uid) {
            $this->join($uid, $this->company, true);
            $this->join($uid, $this->other, true);
        }

        $this->join($this->user, $this->company, true);
        db_connect()->table('user_roles')->insert(['user_id' => $this->user, 'role_id' => $this->roleId('payables_user')]);
        service('permissions')->forget($this->user);

        $route = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->user, 'label' => 'Sue'],
            ['type' => 'approve', 'user' => $approver, 'label' => 'Alan'],
        ]);
        $other = $this->makeRoute($this->other, [
            ['type' => 'submit', 'user' => $this->admin, 'label' => 'Ada'],
            ['type' => 'approve', 'user' => $this->admin, 'label' => 'Ada'],
        ]);

        $engine = new WorkflowEngine($this->db);
        $this->invoice = $this->submitInvoice($engine, $this->user, $this->company, $route['type'], 'INV-ACME');
        $this->hidden  = $this->submitInvoice($engine, $this->admin, $this->other, $other['type'], 'INV-OTHER');
    }

    private function submitInvoice(WorkflowEngine $engine, int $by, int $company, int $type, string $number): int
    {
        $id = $engine->createDraft($by, [
            'company_id' => $company, 'invoice_type_id' => $type, 'supplier' => 'Acme Supplies', 'invoice_number' => $number,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '100.00', 'currency' => 'USD',
        ]);
        (new InvoiceFiles($this->db))->store($id, $by, $this->pdfFile($number), 'inv.pdf');
        $engine->submit($id, $by);

        return $id;
    }

    /**
     * One fresh request as a signed-in user (Shield caches its state per request).
     */
    private function as(int $userId, string $method, string $path, ?array $params = null)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        // Accounts made by UserService must change their password at first sign-in; these tests are about the screens.
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        $call = $this->withSession(['user' => ['id' => $userId]]);

        return $method === 'post' ? $call->post($path, $params) : $call->get($path);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function userScreens(): iterable
    {
        foreach (['payables', 'payables/overview', 'payables/new', 'approvals', 'payables/delegations', 'reports', 'reports/suppliers'] as $path) {
            yield $path => [$path];
        }
    }

    /**
     * @dataProvider userScreens
     */
    public function testScreensRenderForSomeoneWithAccess(string $path): void
    {
        $result = $this->as($this->admin, 'get', $path);

        $this->assertTrue($result->isOK(), "GET /{$path} returned " . $result->response()->getStatusCode());
        $result->assertSee('ITM');
    }

    public function testInvoiceDetailRenders(): void
    {
        $result = $this->as($this->user, 'get', 'payables/' . $this->invoice);

        $this->assertTrue($result->isOK(), 'status ' . $result->response()->getStatusCode());
        $result->assertSee('INV-ACME');
    }

    public function testWorkflowAdminScreensRender(): void
    {
        foreach (['payables-admin/routes', 'payables-admin/routes/new', 'payables-admin/route-variants', 'admin/suppliers', 'payables-admin/default-invoice-types', 'payables-admin/teams', 'payables-admin/teams/new', 'admin/suppliers', 'admin/suppliers/upload', 'admin/suppliers/upload/template'] as $path) {
            $result = $this->as($this->admin, 'get', $path);
            $this->assertTrue($result->isOK(), "GET /{$path} returned " . $result->response()->getStatusCode());
        }
    }

    public function testAdminDashboardLinksToTheWorkflowScreens(): void
    {
        $result = $this->as($this->admin, 'get', 'admin');

        foreach (['admin/suppliers', 'payables-admin/default-invoice-types', 'payables-admin/route-variants'] as $url) {
            $result->assertSee($url);
        }
    }

    public function testOldInvoicesUrlsRedirectToPayables(): void
    {
        $cases = [
            'invoices'                  => 'payables',
            'invoices/overview'         => 'payables/overview',
            'invoices-admin/routes'     => 'payables-admin/routes',
        ];

        foreach ($cases as $old => $new) {
            $result = $this->as($this->admin, 'get', $old);

            $this->assertTrue($result->isRedirect(), "GET /{$old} should redirect");
            $this->assertStringEndsWith($new, $result->getRedirectUrl());
        }

        $this->assertStringEndsWith('tab=payables', $this->as($this->admin, 'get', '/?tab=invoices')->getRedirectUrl());
    }

    public function testTheRegisterOnlyShowsInvoicesOfCompaniesTheUserBelongsTo(): void
    {
        $result = $this->as($this->user, 'get', 'payables');

        $result->assertSee('INV-ACME');
        $result->assertDontSee('INV-OTHER');
    }

    public function testAnInvoiceOfAnotherCompanyIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->as($this->user, 'get', 'payables/' . $this->hidden);
    }

    public function testWorkflowSettingsNeedThePermission(): void
    {
        foreach (['payables-admin/routes', 'payables-admin/teams', 'admin/suppliers'] as $path) {
            $result = $this->as($this->user, 'get', $path);
            $this->assertNotSame(200, $result->response()->getStatusCode(), "GET /{$path} must be gated");
        }
    }

    public function testSomeoneWithoutAnyInvoicePermissionIsKeptOut(): void
    {
        $nobody = $this->makeUserNamed('No Body', 'nobody@example.test');
        $this->join($nobody, $this->company, true);

        $result = $this->as($nobody, 'get', 'payables');

        $this->assertNotSame(200, $result->response()->getStatusCode());
    }
}
