<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdminAccess;
use App\Services\InvoiceFiles;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\ApiTestCase;

/**
 * @internal
 */
final class ApiInvoicesTest extends ApiTestCase
{
    private int $acme;
    private int $beta;
    private User $alan;     // member of ACME
    private User $bob;      // member of BETA
    private User $gary;     // group level
    private User $nobody;   // no companies
    private string $alanToken;
    private string $bobToken;
    private string $garyToken;
    private string $nobodyToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin        = $this->shieldUser('admin@example.test', 'Admin', true);
        $this->alan   = $this->shieldUser('alan@example.test', 'Alan');
        $this->bob    = $this->shieldUser('bob@example.test', 'Bob');
        $this->gary   = $this->shieldUser('gary@example.test', 'Gary');
        $this->nobody = $this->shieldUser('nobody@example.test', 'Nobody');

        $this->acme = $this->makeCompany('ACME');
        $this->beta = $this->makeCompany('BETA');
        $this->join((int) $this->alan->id, $this->acme, true);
        $this->join((int) $this->bob->id, $this->beta, true);
        (new AdminAccess($this->db))->grantGroupLevel((int) $admin->id, (int) $this->gary->id);

        $this->alanToken   = $this->token($this->alan);
        $this->bobToken    = $this->token($this->bob);
        $this->garyToken   = $this->token($this->gary);
        $this->nobodyToken = $this->token($this->nobody);
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function invoice(int $company, array $extra = []): int
    {
        $id = $this->makeInvoice($company, (int) $this->alan->id, $extra['status'] ?? 'in_approval');
        unset($extra['status']);
        $this->db->table('invoices')->where('id', $id)->update($extra + [
            'invoice_number' => 'INV-' . $id, 'supplier' => 'Supplier ' . $id, 'amount' => '100.5', 'currency' => 'USD',
            'due_date' => date('Y-m-d', time() + 86400 * 5), 'invoice_date' => '2026-09-01',
            // things that must never reach the API:
            'route_snapshot' => json_encode(['steps' => [['order' => 1, 'step_type' => 'approve', 'secret' => 'SECRET-ROUTE']]]),
            'sharepoint_url' => 'https://example.sharepoint.com/SECRET-SP', 'sharepoint_item_id' => 'SECRET-ITEM',
        ]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function listed(string $token, array $query = []): array
    {
        $r = $this->api('api/v1/invoices', $token, $query);
        $r->assertStatus(200);

        return $this->json($r)['data'];
    }

    // ------------------------------------------------------------------ scoping

    public function testEachPersonSeesOnlyTheirCompaniesAndGroupLevelSeesAll(): void
    {
        $a = $this->invoice($this->acme);
        $b = $this->invoice($this->beta);

        $this->assertSame([$a], array_column($this->listed($this->alanToken), 'id'));
        $this->assertSame([$b], array_column($this->listed($this->bobToken), 'id'));
        $this->assertEqualsCanonicalizing([$a, $b], array_column($this->listed($this->garyToken), 'id'));
        $this->assertSame([], $this->listed($this->nobodyToken));
    }

    public function testAnInvoiceYouCannotSeeIs404NotForbidden(): void
    {
        $b = $this->invoice($this->beta);

        foreach (['', '/timeline', '/file'] as $suffix) {
            $r = $this->api('api/v1/invoices/' . $b . $suffix, $this->alanToken);
            $r->assertStatus(404);
            $this->assertSame('not_found', $this->json($r)['error']['code']);
        }
        $this->api('api/v1/invoices/999999', $this->alanToken)->assertStatus(404);
        $this->api('api/v1/invoices/' . $b, $this->garyToken)->assertStatus(200);
    }

    public function testCompanyFilterOnlyNarrowsAndRejectsCompaniesYouCannotSee(): void
    {
        $this->invoice($this->acme);
        $b = $this->invoice($this->beta);

        $this->assertSame([$b], array_column($this->listed($this->garyToken, ['company' => $this->beta]), 'id'));

        foreach ([$this->beta, 999999, 'abc'] as $bad) {
            $r = $this->api('api/v1/invoices', $this->alanToken, ['company' => $bad]);
            $r->assertStatus(422);
            $this->assertSame('invalid_filter', $this->json($r)['error']['code']);
        }
    }

    public function testTheSessionsChosenCompanyIsIgnored(): void
    {
        $a = $this->invoice($this->acme);
        $b = $this->invoice($this->beta);
        $_SESSION['active_company'] = $this->beta;   // a stale browser choice must not leak into the API

        $this->assertEqualsCanonicalizing([$a, $b], array_column($this->listed($this->garyToken), 'id'));
    }

    // ------------------------------------------------------------------ list behaviour

    public function testFiltersSortingAndPaging(): void
    {
        $cheap = $this->invoice($this->acme, ['amount' => '10', 'supplier' => 'Zed Ltd', 'status' => 'paid']);
        $mid   = $this->invoice($this->acme, ['amount' => '500', 'supplier' => 'Acme Paper']);
        $big   = $this->invoice($this->acme, ['amount' => '9000', 'supplier' => 'Mega Corp', 'due_date' => date('Y-m-d', time() - 86400 * 40)]);

        $ids = static fn (array $rows): array => array_column($rows, 'id');

        $this->assertSame([$cheap], $ids($this->listed($this->alanToken, ['status' => 'completed'])));
        $this->assertEqualsCanonicalizing([$mid, $big], $ids($this->listed($this->alanToken, ['status' => 'open'])));
        $this->assertSame([$mid], $ids($this->listed($this->alanToken, ['q' => 'paper'])));
        $this->assertSame([$big], $ids($this->listed($this->alanToken, ['min' => '1000'])));
        $this->assertSame([$big], $ids($this->listed($this->alanToken, ['age' => '31-60', 'status' => 'outstanding'])));
        $this->assertSame([$cheap, $mid, $big], $ids($this->listed($this->alanToken, ['sort' => 'amount', 'dir' => 'asc'])));

        $r = $this->api('api/v1/invoices', $this->alanToken, ['per_page' => 2, 'page' => 2, 'sort' => 'amount', 'dir' => 'asc']);
        $body = $this->json($r);
        $this->assertSame([$big], $ids($body['data']));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 3, 'pages' => 2], $body['meta']);

        $capped = $this->json($this->api('api/v1/invoices', $this->alanToken, ['per_page' => 5000]));
        $this->assertSame(100, $capped['meta']['per_page']);
    }

    public function testBadFiltersAre422(): void
    {
        foreach ([['status' => 'bogus'], ['age' => 'x'], ['date_from' => '31/12/2026'], ['min' => 'lots'], ['sort' => 'password'], ['dir' => 'sideways']] as $q) {
            $this->api('api/v1/invoices', $this->alanToken, $q)->assertStatus(422);
        }
    }

    // ------------------------------------------------------------------ shape and secrets

    public function testNothingInternalLeaksAndMoneyIsAFixedString(): void
    {
        $id = $this->invoice($this->acme, ['payment_reference' => 'EFT-77', 'payment_date' => '2026-10-02']);
        $this->db->table('invoice_files')->insert([
            'invoice_id' => $id, 'original_name' => 'x.pdf', 'stored_name' => 'SECRET-STORED.pdf', 'sha256' => 'SECRET-HASH',
            'size_bytes' => 12, 'is_current' => 1, 'uploaded_by' => (int) $this->alan->id, 'uploaded_at' => date('Y-m-d H:i:s'),
        ]);

        $list   = (string) $this->api('api/v1/invoices', $this->alanToken)->response()->getBody();
        $detail = (string) $this->api('api/v1/invoices/' . $id, $this->alanToken)->response()->getBody();
        $queue  = (string) $this->api('api/v1/approvals', $this->alanToken, ['view' => 'mine'])->response()->getBody();
        $report = (string) $this->api('api/v1/reports/outstanding', $this->alanToken)->response()->getBody();

        foreach ([$list, $detail, $queue, $report] as $body) {
            foreach (['SECRET-ROUTE', 'SECRET-SP', 'SECRET-ITEM', 'SECRET-STORED', 'SECRET-HASH', 'route_snapshot', 'sharepoint', 'stored_name', 'sha256', 'password', 'entra'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "leaked {$needle}");
            }
        }

        $inv = $this->json($this->api('api/v1/invoices/' . $id, $this->alanToken))['data'];
        $this->assertSame('100.50', $inv['amount']);
        $this->assertSame('EFT-77', $inv['payment_reference']);
        $this->assertSame('2026-10-02', $inv['payment_date']);
        $this->assertSame('ACME', $inv['company']['code']);
        $this->assertSame('x.pdf', $inv['file']['name']);
        $this->assertFalse($inv['file']['available']);     // no real file on disk in this row
    }

    public function testDetailShowsTheCurrentStepFromTheRouteSnapshot(): void
    {
        $id = $this->invoice($this->acme, [
            'route_snapshot' => json_encode(['steps' => [
                ['order' => 1, 'step_type' => 'submit', 'role' => 'Clerk', 'label' => 'Sue'],
                ['order' => 2, 'step_type' => 'approve', 'role' => 'Accountant', 'label' => 'Alan'],
            ]]),
            'current_step_order' => 2,
        ]);

        $inv = $this->json($this->api('api/v1/invoices/' . $id, $this->alanToken))['data'];

        $this->assertSame(2, $inv['current_step']['order']);
        $this->assertSame('approve', $inv['current_step']['type']);
        $this->assertSame([1, 2], array_column($inv['steps'], 'order'));
        $this->assertSame('Accountant (Alan)', $inv['current_step']['label']);
        $this->assertSame('Clerk (Sue)', $inv['steps'][0]['label']);
    }

    // ------------------------------------------------------------------ file and timeline

    public function testThePdfIsServedWhenPresentAnd404WhenNot(): void
    {
        $id = $this->invoice($this->acme);
        $this->api('api/v1/invoices/' . $id . '/file', $this->alanToken)->assertStatus(404);

        (new InvoiceFiles())->store($id, (int) $this->alan->id, $this->pdfFile('api-file'), 'My Invoice.pdf');

        $r = $this->api('api/v1/invoices/' . $id . '/file', $this->alanToken);
        $r->assertStatus(200);
        $this->assertSame('application/pdf', $r->response()->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $r->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment; filename="My Invoice.pdf"', $r->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $r->response()->getBody());
        $this->assertTrue($this->json($this->api('api/v1/invoices/' . $id, $this->alanToken))['data']['file']['available']);
    }

    public function testSupportingDocumentsAreListedServedAndNeverLeakInternals(): void
    {
        $id = $this->invoice($this->acme);
        $this->db->table('invoice_documents')->insert([
            'invoice_id' => $id, 'original_name' => 'Quote.pdf', 'stored_name' => 'SECRET-DOC-STORED.pdf', 'mime' => 'application/pdf',
            'size_bytes' => 12, 'sha256' => 'SECRET-DOC-HASH', 'uploaded_by' => (int) $this->alan->id, 'uploaded_at' => date('Y-m-d H:i:s'),
            'sharepoint_url' => 'https://example.sharepoint.com/SECRET-DOC-SP',
        ]);
        $listedId = (int) $this->db->insertID();

        $detail = $this->api('api/v1/invoices/' . $id, $this->alanToken);
        $body   = (string) $detail->response()->getBody();
        foreach (['SECRET-DOC-STORED', 'SECRET-DOC-HASH', 'SECRET-DOC-SP', 'stored_name', 'sha256', 'sharepoint'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "leaked {$needle}");
        }
        $docs = $this->json($detail)['data']['documents'];
        $this->assertCount(1, $docs);
        $this->assertSame(['id' => $listedId, 'name' => 'Quote.pdf', 'mime' => 'application/pdf', 'size_bytes' => 12, 'uploaded_by' => 'Alan'], array_diff_key($docs[0], ['uploaded_at' => 1, 'available' => 1]));
        $this->assertFalse($docs[0]['available'], 'no real file on disk in this row');
        $this->api('api/v1/invoices/' . $id . '/documents/' . $listedId, $this->alanToken)->assertStatus(404);

        // A real stored document is served as an attachment with its own content type.
        $realId = (new \App\Services\WorkflowEngine())->addDocument($id, (int) $this->alan->id, $this->pdfFile('api-doc'), 'Real Quote.pdf');
        $r      = $this->api('api/v1/invoices/' . $id . '/documents/' . $realId, $this->alanToken);
        $r->assertStatus(200);
        $this->assertSame('application/pdf', $r->response()->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $r->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment; filename="Real Quote.pdf"', $r->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $r->response()->getBody());

        // Hidden invoices are 404, and a document id never works under another invoice.
        $this->api('api/v1/invoices/' . $id . '/documents/' . $realId, $this->bobToken)->assertStatus(404);
        $this->api('api/v1/invoices/' . $id . '/documents/' . $realId, $this->nobodyToken)->assertStatus(404);
        $this->api('api/v1/invoices/' . $this->invoice($this->acme) . '/documents/' . $realId, $this->alanToken)->assertStatus(404);
    }

    public function testTimelineIsOldestFirstAndHidesInternalIds(): void
    {
        $id   = $this->invoice($this->acme);
        $note = $this->db->table('invoice_notes');
        $note->insert(['invoice_id' => $id, 'user_id' => (int) $this->alan->id, 'kind' => 'note', 'body' => 'First note', 'created_at' => '2026-09-01 09:00:00']);
        $noteId = (int) $this->db->insertID();
        $this->db->table('invoice_actions')->insert([
            'invoice_id' => $id, 'step_order' => 1, 'acted_by' => (int) $this->alan->id, 'acted_as' => 'normal', 'action' => 'approve',
            'note_id' => $noteId, 'acted_at' => '2026-09-01 09:00:00',
        ]);
        $this->db->table('invoice_notes')->insert(['invoice_id' => $id, 'user_id' => (int) $this->alan->id, 'kind' => 'note', 'body' => 'Later note', 'created_at' => '2026-09-02 10:00:00']);

        $events = $this->json($this->api('api/v1/invoices/' . $id . '/timeline', $this->alanToken))['data'];

        $this->assertSame(['action', 'note'], array_column($events, 'type'));
        $this->assertSame('approve', $events[0]['action']);
        $this->assertSame('First note', $events[0]['note']['body']);
        $this->assertSame('Alan', $events[0]['actor']);
        $this->assertSame('Later note', $events[1]['note']['body']);
        $this->assertArrayNotHasKey('id', $events[0]);
        $this->assertArrayNotHasKey('acted_by', $events[0]);
    }

    // ------------------------------------------------------------------ approvals, reports, companies

    public function testApprovalsViewsAndValidation(): void
    {
        $mine = $this->invoice($this->acme, ['status' => 'draft']);

        $r = $this->json($this->api('api/v1/approvals', $this->alanToken, ['view' => 'mine']));
        $this->assertSame([$mine], array_column($r['data']['drafts'], 'id'));
        $this->assertSame(['waiting', 'needs_action', 'drafts'], array_keys($r['data']));

        $this->assertSame([], $this->json($this->api('api/v1/approvals', $this->alanToken))['data']);
        $this->api('api/v1/approvals', $this->alanToken, ['view' => 'everything'])->assertStatus(422);
    }

    public function testOutstandingReportIsScopedAndSummarised(): void
    {
        $this->invoice($this->acme, ['amount' => '100', 'due_date' => date('Y-m-d', time() - 86400 * 70)]);
        $this->invoice($this->beta, ['amount' => '900']);

        $own = $this->json($this->api('api/v1/reports/outstanding', $this->alanToken))['data'];
        $this->assertSame(1, $own['summary']['total']['count']);
        $this->assertSame(['USD' => '100.00'], $own['summary']['total']['amount']);
        $this->assertSame(1, $own['summary']['critical']['count']);
        $this->assertCount(1, $own['invoices']);
        $this->assertSame('ACME', $own['invoices'][0]['company']['code']);

        $all = $this->json($this->api('api/v1/reports/outstanding', $this->garyToken))['data'];
        $this->assertSame(['USD' => '1000.00'], $all['summary']['total']['amount']);

        $this->api('api/v1/reports/outstanding', $this->alanToken, ['company' => $this->beta])->assertStatus(422);
        $this->api('api/v1/reports/outstanding', $this->alanToken, ['group' => 'x'])->assertStatus(422);
    }

    public function testCompaniesEndpointListsOnlyVisibleCompanies(): void
    {
        $this->assertSame(['ACME'], array_column($this->json($this->api('api/v1/companies', $this->alanToken))['data'], 'code'));
        $this->assertEqualsCanonicalizing(['ACME', 'BETA'], array_column($this->json($this->api('api/v1/companies', $this->garyToken))['data'], 'code'));
        $this->assertTrue($this->json($this->api('api/v1/me', $this->garyToken))['data']['is_group_level']);
    }
}
