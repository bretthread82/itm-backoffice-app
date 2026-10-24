<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\ArFiles;
use App\Core\Receivables\ReceivablesEngine;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * Real requests through the full filter pipeline: the customer invoice screens render for someone with access, the
 * permission gate holds, the register only shows what AccessScope allows, and the workflow works end to end over HTTP.
 *
 * @internal
 */
final class ReceivablesScreensTest extends ReceivablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $company;
    private int $other;
    private int $admin;
    private int $raiser;
    private int $approver;
    private int $issuer;
    private int $collector;
    private int $type;
    private int $draft;
    private int $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->company = $this->makeCompany('ACME');
        $this->other   = $this->makeCompany('OTHER');
        $this->admin   = $this->makeUserNamed('Ada Admin', 'ada@example.test', true);
        $this->raiser  = $this->makeUserNamed('Rae Raiser', 'rae@example.test');
        $this->approver  = $this->makeUserNamed('Alan Approver', 'alan@example.test');
        $this->issuer    = $this->makeUserNamed('Ivy Issuer', 'ivy@example.test');
        $this->collector = $this->makeUserNamed('Cal Collector', 'cal@example.test');

        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->approver, $this->company, ['approve']);
        $this->grant($this->issuer, $this->company, ['approve']);
        $this->grant($this->collector, $this->company, ['collect']);
        foreach ([$this->raiser, $this->approver, $this->issuer] as $u) {
            $this->giveRole($u, 'receivables_user');
        }
        $this->giveRole($this->collector, 'receivables_user');

        $route      = $this->makeArRoute($this->company, [
            ['type' => 'submit', 'user' => $this->raiser, 'role' => 'Raiser', 'label' => 'Rae'],
            ['type' => 'approve', 'user' => $this->approver, 'role' => 'Approver', 'label' => 'Alan'],
            ['type' => 'issue', 'user' => $this->issuer, 'role' => 'Billing', 'label' => 'Ivy'],
        ]);
        $this->type = $route['type'];
        $engine     = new ReceivablesEngine($this->db);

        $this->draft = $engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['invoice_number' => 'AR-ACME']));
        (new ArFiles($this->db))->store($this->draft, $this->raiser, $this->pdfFile(), 'inv.pdf');

        $this->grant($this->admin, $this->other, ['submit']);
        $otherRoute   = $this->makeArRoute($this->other, [['type' => 'issue', 'user' => $this->admin]]);
        $this->hidden = $engine->createDraft($this->admin, $this->arDetails($this->other, $otherRoute['type'], ['invoice_number' => 'AR-OTHER']));
    }

    private function giveRole(int $userId, string $role): void
    {
        $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $this->roleId($role)]);
        service('permissions')->forget($userId);
    }

    /** One fresh request as a signed-in user (Shield caches its state per request). */
    private function as(int $userId, string $method, string $path, ?array $params = null)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        // Accounts made by UserService must change their password at first sign-in; these tests are about the screens.
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        $call = $this->withSession(['user' => ['id' => $userId]]);

        return $method === 'post' ? $call->post($path, ($params ?? []) + [csrf_token() => csrf_hash()]) : $call->get($path);
    }

    private function stateOf(int $id): string
    {
        return $this->arRow($id)['status'];
    }

    // ------------------------------------------------------------------ rendering and access

    public function testTheRegisterRendersAndShowsOnlyYourCompanies(): void
    {
        $result = $this->as($this->raiser, 'get', 'receivables');

        $this->assertTrue($result->isOK(), 'status ' . $result->response()->getStatusCode());
        $result->assertSee('AR-ACME');
        $result->assertDontSee('AR-OTHER');
        $result->assertSee('New customer invoice');
    }

    public function testTheSidebarOffersTheReceivablesEntries(): void
    {
        $result = $this->as($this->raiser, 'get', 'receivables');

        foreach (['receivables?status=overdue', 'receivables?status=collecting', 'All customer invoices'] as $needle) {
            $result->assertSee($needle);
        }
    }

    public function testStatusFiltersRenderAndNarrowTheList(): void
    {
        foreach (array_keys(\App\Core\Receivables\ArQuery::STATUS_FILTERS) as $status) {
            $result = $this->as($this->raiser, 'get', 'receivables?status=' . $status);
            $this->assertTrue($result->isOK(), "status filter '{$status}' returned " . $result->response()->getStatusCode());
        }

        $this->as($this->raiser, 'get', 'receivables?status=paid')->assertDontSee('AR-ACME');
        $this->as($this->raiser, 'get', 'receivables?status=draft')->assertSee('AR-ACME');
        $this->as($this->raiser, 'get', 'receivables?q=AR-ACME')->assertSee('AR-ACME');
        $this->as($this->raiser, 'get', 'receivables?q=nothing-like-this')->assertDontSee('AR-ACME');
    }

    public function testAnInvoiceOfAnotherCompanyIsNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->as($this->raiser, 'get', 'receivables/' . $this->hidden);
    }

    public function testSomeoneWithoutReceivablesAccessIsKeptOut(): void
    {
        $nobody = $this->makeUserNamed('No Body', 'nobody@example.test');
        $this->grant($nobody, $this->company, ['submit']);

        foreach (['receivables', 'receivables/new', 'receivables/' . $this->draft] as $path) {
            $this->assertNotSame(200, $this->as($nobody, 'get', $path)->response()->getStatusCode(), "GET /{$path} must be gated");
        }
    }

    public function testTheNewFormNeedsTheRaiseRight(): void
    {
        $this->assertTrue($this->as($this->raiser, 'get', 'receivables/new')->isOK());
        $this->as($this->raiser, 'get', 'receivables/new')->assertSee('Submit invoice');

        $result = $this->as($this->approver, 'get', 'receivables/new');
        $this->assertSame(302, $result->response()->getStatusCode(), 'No raise right: sent back to the register.');
    }

    public function testInvoiceTypesAreOnlyOfferedForCompaniesYouCanRaiseFor(): void
    {
        $json = json_decode($this->as($this->raiser, 'get', 'receivables/types?company=' . $this->company)->getJSON(), true);

        $this->assertSame(['Sales'], array_column($json['types'], 'name'));
        $this->assertSame(['submit', 'approve', 'issue'], array_column($json['types'][0]['steps'], 'type'));

        $this->assertSame(403, $this->as($this->raiser, 'get', 'receivables/types?company=' . $this->other)->response()->getStatusCode());
        $this->assertSame(403, $this->as($this->approver, 'get', 'receivables/types?company=' . $this->company)->response()->getStatusCode());
    }

    public function testTheDraftDetailAndEditFormRender(): void
    {
        $detail = $this->as($this->raiser, 'get', 'receivables/' . $this->draft);
        $this->assertTrue($detail->isOK());
        $detail->assertSee('AR-ACME');
        $detail->assertSee('Submit for approval');

        $edit = $this->as($this->raiser, 'get', 'receivables/' . $this->draft . '/edit');
        $this->assertTrue($edit->isOK());
        $edit->assertSee('Save draft');

        // Someone else cannot edit it.
        $this->assertSame(302, $this->as($this->approver, 'get', 'receivables/' . $this->draft . '/edit')->response()->getStatusCode());
    }

    public function testTheInvoicePdfIsServedToThoseWhoCanSeeIt(): void
    {
        $result = $this->as($this->raiser, 'get', 'receivables/' . $this->draft . '/file');

        $this->assertTrue($result->isOK());
        $this->assertSame('application/pdf', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $result->response()->getBody());
    }

    public function testCsvExportIsLimitedToWhatYouMaySee(): void
    {
        $csv = (string) $this->as($this->raiser, 'get', 'receivables/export.csv')->response()->getBody();

        $this->assertStringContainsString('AR-ACME', $csv);
        $this->assertStringNotContainsString('AR-OTHER', $csv);
        $this->assertStringContainsString('Outstanding', $csv);
    }

    public function testHelpIsOnlyShownToThoseWhoCanUseTheScreens(): void
    {
        $nobody = $this->makeUserNamed('No Body', 'nobody@example.test');

        $this->assertContains('receivables', array_keys(service('help')->topics($this->raiser)));
        $this->assertNotContains('receivables', array_keys(service('help')->topics($nobody)));
        $this->assertContains('Recording payments', array_column(service('help')->topic($this->collector, 'receivables')['sections'], 'heading'));

        setting()->set('ModuleState.disabled', ['receivables']);
        Services::resetSingle('modules');
        Services::resetSingle('help');
        $this->assertNotContains('receivables', array_keys(service('help')->topics($this->raiser)), 'A switched-off module takes its help with it.');
        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
    }

    public function testSwitchingTheModuleOffBlocksTheScreens(): void
    {
        setting()->set('ModuleState.disabled', ['receivables']);
        Services::resetSingle('modules');

        try {
            $this->assertNotSame(200, $this->as($this->raiser, 'get', 'receivables')->response()->getStatusCode());
        } finally {
            setting()->set('ModuleState.disabled', []);
            Services::resetSingle('modules');
        }
    }

    // ------------------------------------------------------------------ the workflow over HTTP

    public function testRaiseApproveIssueAndCollectOverHttp(): void
    {
        // Submit
        $this->as($this->raiser, 'post', 'receivables/' . $this->draft . '/submit');
        $this->assertSame('in_approval', $this->stateOf($this->draft));

        // The approver sees the action bar and approves
        $this->as($this->approver, 'get', 'receivables/' . $this->draft)->assertSee('Waiting for you');
        $this->as($this->approver, 'post', 'receivables/' . $this->draft . '/act', ['decision' => 'approve', 'note' => '']);
        $this->assertSame('approved', $this->stateOf($this->draft));

        // Issuing needs to know who it went to
        $this->as($this->issuer, 'get', 'receivables/' . $this->draft)->assertSee('Mark as issued');
        $this->as($this->issuer, 'post', 'receivables/' . $this->draft . '/act', ['decision' => 'approve', 'note' => '', 'sent_to' => '']);
        $this->assertSame('approved', $this->stateOf($this->draft), 'Missing "sent to" keeps it at the issue step.');
        $this->as($this->issuer, 'post', 'receivables/' . $this->draft . '/act', ['decision' => 'approve', 'note' => '', 'sent_to' => 'ap@harbourfoods.example']);
        $this->assertSame('issued', $this->stateOf($this->draft));
        $this->assertSame('ap@harbourfoods.example', $this->arRow($this->draft)['sent_to']);

        // The collector sees the payments tab and records a part payment, then the rest
        $page = $this->as($this->collector, 'get', 'receivables/' . $this->draft);
        $page->assertSee('Record a payment');
        $this->as($this->collector, 'post', 'receivables/' . $this->draft . '/payments', ['amount' => '100.00', 'received_date' => service('timezone')->todayFor($this->company), 'method' => 'bank_transfer', 'reference' => 'WIRE-1']);
        $this->assertSame('part_paid', $this->stateOf($this->draft));
        $this->as($this->collector, 'post', 'receivables/' . $this->draft . '/payments', ['amount' => '150.00', 'received_date' => service('timezone')->todayFor($this->company)]);
        $this->assertSame('paid', $this->stateOf($this->draft));

        // Reverse the first receipt over HTTP
        $first = (int) $this->db->table('ar_payments')->orderBy('id')->get(1)->getRow()->id;
        $this->as($this->collector, 'post', 'receivables/' . $this->draft . '/payments/' . $first . '/reverse', ['reason' => 'Bank returned it']);
        $this->assertSame('part_paid', $this->stateOf($this->draft));
        $this->assertSame('150.00', $this->arRow($this->draft)['amount_paid']);
    }

    public function testOnlyCollectorsCanRecordOrReversePayments(): void
    {
        $this->issue($this->draft);
        $today = service('timezone')->todayFor($this->company);

        // Approver and raiser hold receivables.view but not the collect permission or right
        $this->as($this->approver, 'post', 'receivables/' . $this->draft . '/payments', ['amount' => '10.00', 'received_date' => $today]);
        $this->as($this->raiser, 'post', 'receivables/' . $this->draft . '/payments', ['amount' => '10.00', 'received_date' => $today]);
        $this->assertSame(0, $this->db->table('ar_payments')->countAllResults());

        $this->as($this->collector, 'post', 'receivables/' . $this->draft . '/payments', ['amount' => '10.00', 'received_date' => $today]);
        $id = (int) $this->db->table('ar_payments')->get()->getRow()->id;

        $this->as($this->raiser, 'post', 'receivables/' . $this->draft . '/payments/' . $id . '/reverse', ['reason' => 'Nope']);
        $this->assertSame(1, $this->db->table('ar_payments')->countAllResults());
    }

    public function testAReceiptCannotBeReversedThroughAnotherInvoicesUrl(): void
    {
        $this->issue($this->draft);
        $today = service('timezone')->todayFor($this->company);
        $this->as($this->collector, 'post', 'receivables/' . $this->draft . '/payments', ['amount' => '10.00', 'received_date' => $today]);
        $id    = (int) $this->db->table('ar_payments')->get()->getRow()->id;
        $other = $this->makeIssued($this->company, $this->raiser);

        $this->as($this->collector, 'post', 'receivables/' . $other . '/payments/' . $id . '/reverse', ['reason' => 'Wrong URL']);

        $this->assertSame(1, $this->db->table('ar_payments')->countAllResults(), 'No reversal was written.');
    }

    public function testReturningNeedsANoteOverHttp(): void
    {
        $this->as($this->raiser, 'post', 'receivables/' . $this->draft . '/submit');

        $this->as($this->approver, 'post', 'receivables/' . $this->draft . '/act', ['decision' => 'return', 'note' => '']);
        $this->assertSame('in_approval', $this->stateOf($this->draft));

        $this->as($this->approver, 'post', 'receivables/' . $this->draft . '/act', ['decision' => 'return', 'note' => 'Wrong PO']);
        $this->assertSame('returned', $this->stateOf($this->draft));

        $this->as($this->raiser, 'get', 'receivables/' . $this->draft)->assertSee('What did you change?');
    }

    public function testCreatingADraftOverHttpKeepsWhatWasTyped(): void
    {
        $post = [
            'company_id' => (string) $this->company, 'invoice_type_id' => (string) $this->type, 'customer' => 'Walk-in Buyer', 'invoice_number' => 'AR-NEW',
            'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31', 'amount' => '75.50', 'currency' => 'USD', 'action' => 'save',
        ];

        $this->as($this->raiser, 'post', 'receivables', ['company_id' => (string) $this->company] + $post);

        $row = $this->db->table('ar_invoices')->where('invoice_number', 'AR-NEW')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('draft', $row['status']);
        $this->assertSame('75.50', $row['amount']);
        $this->assertSame('Walk-in Buyer', $row['customer']);
    }

    public function testASaveWithMissingDetailsListsEverythingAndCreatesNothing(): void
    {
        $before = $this->db->table('ar_invoices')->countAllResults();

        $result = $this->as($this->raiser, 'post', 'receivables', ['company_id' => (string) $this->company, 'action' => 'save']);

        $this->assertSame($before, $this->db->table('ar_invoices')->countAllResults());
        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString('Invoice number is required', (string) session()->getFlashdata('error'));
    }

    private function issue(int $id): void
    {
        $this->db->table('ar_invoices')->where('id', $id)->update(['status' => 'issued', 'sent_to' => 'x', 'issued_at' => date('Y-m-d H:i:s')]);
    }
}
