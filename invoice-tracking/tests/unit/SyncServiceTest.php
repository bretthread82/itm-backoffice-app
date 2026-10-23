<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InvoiceFiles;
use App\Services\SharePoint\Connections;
use App\Services\SharePoint\SecretBox;
use App\Services\SharePoint\SharePointException;
use App\Services\SharePoint\SyncService;
use App\Services\WorkflowEngine;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;
use Tests\Support\FakeSharePointClient;

/**
 * @internal
 */
final class SyncServiceTest extends AppTestCase
{
    private FakeSharePointClient $sp;
    private FakeMailTransport $mail;
    private SyncService $sync;
    private int $company;
    private int $type;
    private int $submitter;
    private int $approver;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sp   = new FakeSharePointClient();
        $this->mail = new FakeMailTransport();
        Services::injectMock('sharepoint', $this->sp);
        Services::injectMock('mailer', $this->mail);
        $this->sync = new SyncService($this->db, $this->sp);

        $this->admin     = $this->makeUserWithEmail('Admin', 'admin@example.test', true);
        $this->company   = $this->makeCompany('ACME');
        $this->submitter = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $this->approver  = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $this->join($this->submitter, $this->company, true);
        $this->join($this->approver, $this->company);
        $this->type = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->approver, 'role' => 'Accountant', 'label' => 'Alan'],
        ])['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('sharepoint');
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    // -------------------------------------------------------------- helpers

    /** An enabled, complete connection. $default makes it the default. Returns its id. */
    private function connection(bool $enabled = true, bool $default = true, string $name = 'Main', array $over = []): int
    {
        $this->db->table('sharepoint_connections')->insert($over + [
            'name' => $name, 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => SecretBox::seal('the-secret'),
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'list_name' => 'Completed Invoices', 'library_name' => 'Documents',
            'folder' => 'Invoices', 'field_mapping' => json_encode(['Title' => 'invoice_number', 'Supplier' => 'supplier']),
            'enabled' => $enabled ? 1 : 0, 'is_default' => $default ? 1 : 0,
        ]);

        return (int) $this->db->insertID();
    }

    /** A completed invoice, as the workflow would leave it. */
    private function completedInvoice(string $number = 'INV-1'): int
    {
        $engine = new WorkflowEngine($this->db);
        $id     = $engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => 'Acme Supplies', 'invoice_number' => $number,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '480.00', 'currency' => 'USD',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile($number), 'inv.pdf');
        $engine->submit($id, $this->submitter);
        $engine->approve($id, $this->approver, 'Looks right');

        return $id;
    }

    /** @return array<string, mixed> */
    private function queue(int $invoiceId): array
    {
        return $this->db->table('sync_queue')->where('invoice_id', $invoiceId)->get()->getRowArray();
    }

    private function inFuture(string $datetime, int $minutes, int $slack = 90): bool
    {
        return abs((strtotime($datetime) - time()) - $minutes * 60) <= $slack;
    }

    // -------------------------------------------------------------- nothing is sent without a usable connection

    public function testWithNoConnectionNothingIsSentAndTheInvoiceWaits(): void
    {
        $id = $this->completedInvoice();

        $this->assertSame('not_configured', $this->invoiceRow($id)['sync_status']);
        $this->assertSame('not_configured', $this->sync->process($id));
        $this->assertSame([], $this->sp->stored);
        $this->assertSame('not_configured', $this->queue($id)['status']);
    }

    public function testADisabledConnectionNeverReceivesAnything(): void
    {
        $this->connection(false);
        $id = $this->completedInvoice();

        $this->sync->run();

        $this->assertSame([], $this->sp->stored);
        $this->assertSame([], $this->sp->tested);
        $this->assertSame('not_configured', $this->invoiceRow($id)['sync_status']);
    }

    public function testAnIncompleteConnectionNeverReceivesAnything(): void
    {
        $this->connection(true, true, 'No secret', ['client_secret' => null]);
        $this->completedInvoice();

        $this->sync->run();

        $this->assertSame([], $this->sp->stored);
    }

    public function testBacklogIsPickedUpAutomaticallyOnceAConnectionIsSwitchedOn(): void
    {
        $a = $this->completedInvoice('A');
        $b = $this->completedInvoice('B');
        $this->sync->run();
        $this->assertSame([], $this->sp->stored);

        $this->connection(true);
        $result = $this->sync->run();

        $this->assertSame(2, $result['synced']);
        $this->assertCount(2, $this->sp->stored);
        foreach ([$a, $b] as $id) {
            $this->assertSame('synced', $this->invoiceRow($id)['sync_status']);
            $this->assertSame('synced', $this->queue($id)['status']);
        }
    }

    public function testOnlyCompletedInvoicesAreEverSent(): void
    {
        $this->connection();
        $draft = $this->makeInvoice($this->company, $this->submitter, 'in_approval');
        $this->db->table('sync_queue')->insert(['invoice_id' => $draft, 'status' => 'pending', 'attempts' => 0]);

        $this->assertSame('skipped', $this->sync->process($draft));
        $this->assertSame([], $this->sp->stored);
    }

    // -------------------------------------------------------------- which connection

    public function testACompanyWithItsOwnConnectionUsesOnlyThatOne(): void
    {
        $default = $this->connection(true, true, 'Default');
        $own     = $this->connection(true, false, 'Own', ['site_url' => 'https://contoso.sharepoint.com/sites/Acme']);
        $this->db->table('companies')->where('id', $this->company)->update(['sharepoint_connection_id' => $own]);
        $id = $this->completedInvoice();

        $this->sync->run();

        $this->assertSame('https://contoso.sharepoint.com/sites/Acme', $this->sp->stored[0]['connection']['site_url']);
        $this->assertSame($own, (int) $this->queue($id)['connection_id']);
        $this->assertNotSame($default, $own);
    }

    public function testACompanyAssignedADisabledConnectionDoesNotFallBackToTheDefault(): void
    {
        $this->connection(true, true, 'Default');
        $own = $this->connection(false, false, 'Own (off)');
        $this->db->table('companies')->where('id', $this->company)->update(['sharepoint_connection_id' => $own]);
        $this->completedInvoice();

        $this->sync->run();

        $this->assertSame([], $this->sp->stored, 'Invoices must not silently go to a different SharePoint site.');
    }

    // -------------------------------------------------------------- what is sent

    public function testTheSecretIsDecryptedForTheClientAndThePayloadIsComplete(): void
    {
        $this->connection();
        $id = $this->completedInvoice('INV-77');

        $this->sync->process($id);

        $call = $this->sp->stored[0];
        $this->assertSame('the-secret', $call['connection']['secret']);
        $this->assertSame('tenant-1', $call['connection']['tenant']);
        $this->assertSame(['Title' => 'invoice_number', 'Supplier' => 'supplier'], $call['connection']['mapping']);
        $this->assertStringEndsWith('_' . $id . '.pdf', $call['name']);
        $this->assertStringStartsWith('INV-77_Acme_Supplies', $call['name']);
        $this->assertGreaterThan(0, $call['bytes']);

        $p = $call['payload'];
        $this->assertSame($id, $p['invoice_id']);
        $this->assertSame('INV-77', $p['invoice_number']);
        $this->assertSame('Acme Supplies', $p['supplier']);
        $this->assertSame('ACME', $p['company_code']);
        $this->assertSame('Standard', $p['invoice_type']);
        $this->assertSame('Sue', $p['submitted_by']);
        $this->assertStringContainsString('Alan on ' . date('Y-m-d'), $p['approval_summary']);
        $this->assertStringEndsWith('invoices/' . $id, $p['app_url']);
    }

    public function testApprovalSummaryNamesDelegatesAndGroupLevelOverrides(): void
    {
        $this->connection();
        $gary = $this->makeUserWithEmail('Gary', 'gary@example.test');
        (new \App\Services\AdminAccess($this->db))->grantGroupLevel($this->admin, $gary);

        $engine = new WorkflowEngine($this->db);
        $id     = $engine->createDraft($this->submitter, ['company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => 'S', 'invoice_number' => 'G-1', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '5']);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'a.pdf');
        $engine->submit($id, $this->submitter);
        $engine->approve($id, $gary, 'Alan is away');

        $this->sync->process($id);

        $this->assertStringContainsString('Gary (group-level for Alan)', $this->sp->stored[0]['payload']['approval_summary']);
    }

    public function testASuccessfulSyncRecordsTheResultAndLeavesTheFileAlone(): void
    {
        $this->connection();
        $id = $this->completedInvoice();

        $this->assertSame('synced', $this->sync->process($id));

        $inv = $this->invoiceRow($id);
        $this->assertSame('synced', $inv['sync_status']);
        $this->assertNotNull($inv['synced_at']);
        $this->assertStringStartsWith('https://sp.test/files/', $inv['sharepoint_url']);
        $this->assertStringStartsWith('item-', $inv['sharepoint_item_id']);
        $q = $this->queue($id);
        $this->assertSame('synced', $q['status']);
        $this->assertSame(1, (int) $q['attempts']);
        $this->assertNull($q['last_error']);
        $this->assertSame(0, (int) $this->db->table('invoice_files')->where('invoice_id', $id)->get()->getRow()->purged_at ? 1 : 0); // purging is a separate job
    }

    public function testASyncedInvoiceIsNotSentAgain(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->sync->process($id);

        $this->sync->run();
        $this->sync->process($id);

        $this->assertCount(1, $this->sp->stored);
    }

    // -------------------------------------------------------------- failures and back-off

    public function testBackoffIsFiveMinutesDoublingAndCappedAtSixHours(): void
    {
        $this->assertSame([5, 10, 20, 40, 80, 160, 320, 360, 360], array_map(SyncService::backoffMinutes(...), [1, 2, 3, 4, 5, 6, 7, 8, 9]));
    }

    public function testAFailureIsRetriedLaterNotImmediatelyAndTheErrorIsKept(): void
    {
        $this->connection();
        $id                = $this->completedInvoice();
        $this->sp->failures = [new SharePointException('SharePoint returned HTTP 503')];

        $this->assertSame('retry', $this->sync->process($id));

        $q = $this->queue($id);
        $this->assertSame('pending', $q['status']);
        $this->assertSame(1, (int) $q['attempts']);
        $this->assertSame('SharePoint returned HTTP 503', $q['last_error']);
        $this->assertTrue($this->inFuture($q['next_attempt_at'], 5));
        $this->assertSame('pending', $this->invoiceRow($id)['sync_status']);
        $this->assertSame('SharePoint returned HTTP 503', $this->db->table('sharepoint_connections')->get()->getRow()->last_error);

        // Not due yet: the scheduled run leaves it alone.
        $this->assertSame(0, $this->sync->run()['processed']);
        $this->assertCount(1, $this->sp->stored);

        // Once due, it succeeds and the error is cleared.
        $this->db->table('sync_queue')->where('invoice_id', $id)->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame(1, $this->sync->run()['synced']);
        $this->assertNull($this->queue($id)['last_error']);
        $this->assertSame(2, (int) $this->queue($id)['attempts']);
    }

    public function testRetryDelaysGrowWithEachFailure(): void
    {
        $this->connection();
        $id                 = $this->completedInvoice();
        $this->sp->failures = array_fill(0, 3, new SharePointException('down'));

        $expected = [5, 10, 20];
        foreach ($expected as $i => $minutes) {
            $this->db->table('sync_queue')->where('invoice_id', $id)->update(['next_attempt_at' => null]);
            $this->sync->process($id);
            $this->assertTrue($this->inFuture($this->queue($id)['next_attempt_at'], $minutes), 'attempt ' . ($i + 1));
        }
    }

    public function testAfterTheLastAttemptItGivesUpAndTellsEveryAdminOnce(): void
    {
        $this->connection();
        $other = $this->makeUserWithEmail('Admin Two', 'admin2@example.test', true);
        $id    = $this->completedInvoice('FAIL-1');
        $this->mail->sent   = [];
        $this->sp->failures = array_fill(0, SyncService::MAX_ATTEMPTS, new SharePointException('Column "Supplier" does not exist'));

        for ($i = 1; $i < SyncService::MAX_ATTEMPTS; $i++) {
            $this->db->table('sync_queue')->where('invoice_id', $id)->update(['next_attempt_at' => null]);
            $this->assertSame('retry', $this->sync->process($id), 'attempt ' . $i);
        }
        $this->assertSame(0, $this->db->table('user_notifications')->where('event', 'sync.failed')->countAllResults()); // not yet

        $this->db->table('sync_queue')->where('invoice_id', $id)->update(['next_attempt_at' => null]);
        $this->assertSame('failed', $this->sync->process($id));

        $q = $this->queue($id);
        $this->assertSame('failed', $q['status']);
        $this->assertNull($q['next_attempt_at']);
        $this->assertSame(SyncService::MAX_ATTEMPTS, (int) $q['attempts']);
        $this->assertSame('failed', $this->invoiceRow($id)['sync_status']);

        foreach ([$this->admin, $other] as $adminId) {
            $this->assertSame(1, $this->db->table('user_notifications')->where(['user_id' => $adminId, 'event' => 'sync.failed'])->countAllResults());
        }
        $this->assertCount(1, $this->mail->to('admin@example.test'));
        $html = $this->mail->to('admin@example.test')[0]->html;
        $this->assertStringContainsString('Column &quot;Supplier&quot; does not exist', $html);
        $this->assertStringContainsString('href="' . site_url('invoices/' . $id) . '"', $html);   // a real, clickable address
        $this->assertCount(0, $this->mail->to('sue@example.test'));                  // not a user-facing problem

        // A failed invoice is not retried by the schedule: an admin must act.
        $count = count($this->sp->stored);
        $this->sync->run();
        $this->assertCount($count, $this->sp->stored);
    }

    public function testAnUnexpectedErrorIsHandledLikeAnyOtherFailure(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->db->table('sharepoint_connections')->update(['client_secret' => 'not-validly-sealed']); // cannot be decrypted

        $this->assertSame('retry', $this->sync->process($id));
        $this->assertNotEmpty($this->queue($id)['last_error']);
        $this->assertStringNotContainsString('the-secret', (string) $this->queue($id)['last_error']);
    }

    public function testAPdfThatIsGoneFailsImmediatelyWithAClearReason(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->db->table('invoice_files')->where('invoice_id', $id)->update(['purged_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('failed', $this->sync->process($id));
        $this->assertStringContainsString('no longer on the server', $this->queue($id)['last_error']);
        $this->assertSame([], $this->sp->stored);
    }

    // -------------------------------------------------------------- resuming

    public function testARetryResumesAfterTheFileWasStoredInsteadOfUploadingAgain(): void
    {
        $this->connection();
        $id                   = $this->completedInvoice();
        $this->sp->failAfterFile = true;                                    // file step works, item step fails

        $this->assertSame('retry', $this->sync->process($id));
        $inv = $this->invoiceRow($id);
        $this->assertNotEmpty($inv['sharepoint_url']);                       // saved straight away
        $this->assertEmpty($inv['sharepoint_item_id']);
        $savedUrl = $inv['sharepoint_url'];

        $this->db->table('sync_queue')->where('invoice_id', $id)->update(['next_attempt_at' => null]);
        $this->assertSame('synced', $this->sync->process($id));

        $second = $this->sp->stored[1];
        $this->assertSame($savedUrl, $second['fileUrl']);                    // told the file is already there
        $this->assertNull($second['itemId']);
        $this->assertSame('synced', $this->invoiceRow($id)['sync_status']);
    }

    // -------------------------------------------------------------- concurrency

    public function testARowAlreadyBeingProcessedIsLeftAlone(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->db->table('sync_queue')->where('invoice_id', $id)->update(['status' => 'syncing', 'updated_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('skipped', $this->sync->process($id));
        $this->assertSame([], $this->sp->stored);
        $this->assertSame(0, $this->sync->run()['processed']);
    }

    public function testARowAbandonedByACrashedJobIsTakenOver(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->db->table('sync_queue')->where('invoice_id', $id)->update(['status' => 'syncing', 'updated_at' => date('Y-m-d H:i:s', time() - 7200)]);

        $this->assertSame(1, $this->sync->run()['synced']);
        $this->assertCount(1, $this->sp->stored);
    }

    public function testRunRespectsTheLimitAndGoesOldestFirst(): void
    {
        $this->connection();
        $a = $this->completedInvoice('A');
        $b = $this->completedInvoice('B');
        $c = $this->completedInvoice('C');

        $this->assertSame(2, $this->sync->run(2)['processed']);
        $this->assertSame([$a, $b], array_map(static fn (array $s): int => $s['payload']['invoice_id'], $this->sp->stored));
        $this->assertSame('not_configured', $this->invoiceRow($c)['sync_status'] === 'pending' ? 'not_configured' : $this->invoiceRow($c)['sync_status']); // still waiting
        $this->assertSame('pending', $this->queue($c)['status']);
    }

    // -------------------------------------------------------------- admin retry

    public function testAdminRetryResetsAFailedInvoiceAndSendsItNow(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->db->table('sync_queue')->where('invoice_id', $id)->update(['status' => 'failed', 'attempts' => SyncService::MAX_ATTEMPTS, 'last_error' => 'old error']);
        $this->db->table('invoices')->where('id', $id)->update(['sync_status' => 'failed']);

        $this->assertSame('synced', $this->sync->retry($id, $this->admin));

        $q = $this->queue($id);
        $this->assertSame('synced', $q['status']);
        $this->assertSame(1, (int) $q['attempts']);                          // counting restarted
        $this->assertNull($q['last_error']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'sharepoint.retry')->countAllResults());
    }

    public function testRetryCannotDisturbAnInvoiceThatIsBeingProcessedOrAlreadySynced(): void
    {
        $this->connection();
        $id = $this->completedInvoice();
        $this->sync->process($id);

        $this->assertSame('skipped', $this->sync->retry($id, $this->admin));
        $this->assertCount(1, $this->sp->stored);
    }

    public function testResolvingTheConnectionUsesCurrentSettingsEachTime(): void
    {
        $conn = new Connections($this->db);
        $this->assertNull($conn->forCompany($this->company));

        $id = $this->connection(true);
        $this->assertSame($id, $conn->idFor($this->company));

        $this->db->table('sharepoint_connections')->where('id', $id)->update(['enabled' => 0]);
        $this->assertNull($conn->forCompany($this->company));
    }
}
