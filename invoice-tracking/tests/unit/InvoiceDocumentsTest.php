<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ApprovalQueue;
use App\Services\FilePurger;
use App\Services\InvoiceDocuments;
use App\Services\InvoiceFiles;
use App\Services\SharePoint\Connections;
use App\Services\SharePoint\SecretBox;
use App\Services\SharePoint\SyncService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowException;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;
use Tests\Support\FakeSharePointClient;

/**
 * Supporting documents attached while an invoice is being approved: validation, who may attach, the audit trail,
 * filing to SharePoint and the file purge.
 *
 * @internal
 */
final class InvoiceDocumentsTest extends AppTestCase
{
    private WorkflowEngine $engine;
    private FakeSharePointClient $sp;
    private int $company;
    private int $type;
    private int $submitter;
    private int $approver;
    private int $other;
    private int $outsider;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sp = new FakeSharePointClient();
        Services::injectMock('mailer', new FakeMailTransport());
        Services::injectMock('sharepoint', $this->sp);
        $this->engine = new WorkflowEngine($this->db);

        $this->admin     = $this->makeUserWithEmail('Admin', 'admin@example.test', true);
        $this->company   = $this->makeCompany('ACME');
        $this->submitter = $this->makeUserWithEmail('Sue Submitter', 'sue@example.test');
        $this->approver  = $this->makeUserWithEmail('Alan Approver', 'alan@example.test');
        $this->other     = $this->makeUserWithEmail('Olive Other', 'olive@example.test');
        $this->outsider  = $this->makeUserWithEmail('Otto Outsider', 'otto@example.test');
        $this->join($this->submitter, $this->company, true);
        $this->join($this->approver, $this->company);
        $this->join($this->other, $this->company);

        $this->type = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->approver, 'role' => 'Accountant', 'label' => 'Alan'],
        ])['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        Services::resetSingle('sharepoint');
        parent::tearDown();
    }

    // -------------------------------------------------------------- helpers

    private function draft(): int
    {
        $id = $this->engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => 'Acme', 'invoice_number' => 'INV-1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '100.00', 'currency' => 'usd',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'inv.pdf');

        return $id;
    }

    private function inApproval(): int
    {
        $id = $this->draft();
        $this->engine->submit($id, $this->submitter);

        return $id;
    }

    /** A small but structurally real Office file; $extra adds entries (for example a macro project). */
    private function officeFile(string $ext, array $extra = []): string
    {
        $path = $this->tempFile('');
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml', '<x/>');
        foreach ($extra as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        return $path;
    }

    private function docs(int $invoiceId): array
    {
        return (new InvoiceDocuments($this->db))->forInvoice($invoiceId);
    }

    private function connection(): void
    {
        $this->db->table('sharepoint_connections')->insert([
            'name' => 'Main', 'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => SecretBox::seal('s'),
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'list_name' => 'Completed Invoices', 'library_name' => 'Documents',
            'folder' => 'Invoices', 'field_mapping' => json_encode(['Title' => 'invoice_number']), 'enabled' => 1, 'is_default' => 1,
        ]);
    }

    // -------------------------------------------------------------- validation

    public function testAllowedTypesAreAccepted(): void
    {
        $docs = new InvoiceDocuments($this->db);

        $docs->validate($this->pdfFile(), 'quote.pdf');
        $docs->validate($this->tempFile(self::pngBytes()), 'photo.png');
        $docs->validate($this->officeFile('docx'), 'letter.docx');
        $docs->validate($this->officeFile('xlsx'), 'sheet.xlsx');
        $docs->validate($this->tempFile("From: a@example.test\r\nSubject: PO\r\n\r\nPlease pay.\r\n"), 'po.eml');

        $this->addToAssertionCount(1);
    }

    /** @dataProvider badFiles */
    public function testBadFilesAreRefused(string $name, string $bytes): void
    {
        $this->expectException(WorkflowException::class);

        (new InvoiceDocuments($this->db))->validate($this->tempFile($bytes), $name);
    }

    /** @return array<string, array{string, string}> */
    public static function badFiles(): array
    {
        return [
            'program'             => ['run.exe', 'MZ' . str_repeat("\0", 50)],
            'script renamed'      => ['notes.pdf', '<?php echo 1;'],
            'png that is not'     => ['photo.png', 'not a png at all'],
            'empty'               => ['empty.pdf', ''],
            'pdf with javascript' => ['bad.pdf', "%PDF-1.4\n<< /JavaScript (x) >>\n%%EOF\n"],
            'no extension'        => ['quote', "%PDF-1.4\n%%EOF\n"],
        ];
    }

    public function testOfficeFilesWithMacrosOrEmbeddedObjectsAreRefused(): void
    {
        $docs = new InvoiceDocuments($this->db);

        foreach (['word/vbaProject.bin', 'word/embeddings/oleObject1.bin', 'word/activeX/activeX1.xml'] as $entry) {
            try {
                $docs->validate($this->officeFile('docx', [$entry => 'x']), 'macro.docx');
                $this->fail("{$entry} should be refused");
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('macros or embedded objects', $e->getMessage());
            }
        }
    }

    public function testAZipWithoutTheOfficeStructureIsRefused(): void
    {
        $this->expectException(WorkflowException::class);

        $path = $this->tempFile('');
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'hi');
        $zip->close();

        (new InvoiceDocuments($this->db))->validate($path, 'fake.docx');
    }

    // -------------------------------------------------------------- who may attach

    public function testTheCurrentApproverAttachesADocumentAndItIsRecorded(): void
    {
        $id = $this->inApproval();

        $docId = $this->engine->addDocument($id, $this->approver, $this->pdfFile('po'), 'purchase-order.pdf', 'PO for the audit');

        $docs = $this->docs($id);
        $this->assertCount(1, $docs);
        $this->assertSame($docId, (int) $docs[0]['id']);
        $this->assertSame('purchase-order.pdf', $docs[0]['original_name']);
        $this->assertSame('application/pdf', $docs[0]['mime']);
        $this->assertSame('normal', $docs[0]['acted_as']);
        $this->assertFileExists((string) (new InvoiceDocuments($this->db))->path($docs[0]));

        $last = array_values(array_filter($this->actions($id), static fn (array $a): bool => $a['action'] === 'attach'));
        $this->assertCount(1, $last);
        $this->assertSame($this->approver, (int) $last[0]['acted_by']);
        $this->assertSame('PO for the audit', $this->db->table('invoice_notes')->where('id', $last[0]['note_id'])->get()->getRowArray()['body']);
        $this->assertSame('invoice.document_added', $this->db->table('audit_log')->orderBy('id', 'DESC')->get()->getRowArray()['event']);

        // The invoice itself did not move, and its PDF is still the current file.
        $this->assertSame('in_approval', $this->invoiceRow($id)['status']);
        $this->assertSame('inv.pdf', (new InvoiceFiles($this->db))->current($id)['original_name']);
    }

    public function testTheSubmitterMayAttachWhileInApprovalAndOnADraft(): void
    {
        $draft = $this->draft();
        $this->engine->addDocument($draft, $this->submitter, $this->pdfFile('a'), 'a.pdf');

        $live = $this->inApproval();
        $this->engine->addDocument($live, $this->submitter, $this->pdfFile('b'), 'b.pdf');

        $this->assertCount(1, $this->docs($draft));
        $this->assertCount(1, $this->docs($live));
    }

    public function testPeopleWhoCannotActOrSeeTheInvoiceAreRefused(): void
    {
        $id = $this->inApproval();

        foreach ([$this->other, $this->outsider] as $user) {
            try {
                $this->engine->addDocument($id, $user, $this->pdfFile('x'), 'x.pdf');
                $this->fail('User ' . $user . ' should not be able to attach');
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('cannot attach', $e->getMessage());
            }
        }

        $this->assertSame([], $this->docs($id));
        $this->assertFalse($this->engine->canAttach($id, $this->other)['allowed']);
        $this->assertTrue($this->engine->canAttach($id, $this->approver)['allowed']);
    }

    public function testNothingCanBeAttachedOnceTheInvoiceIsFinished(): void
    {
        $id = $this->inApproval();
        $this->engine->approve($id, $this->approver);
        $this->assertContains($this->invoiceRow($id)['status'], ['processed', 'paid']);

        $this->expectException(WorkflowException::class);
        $this->engine->addDocument($id, $this->approver, $this->pdfFile('late'), 'late.pdf');
    }

    public function testAGroupLevelUserMustGiveAReason(): void
    {
        $id = $this->inApproval();
        $gl = $this->makeUserWithEmail('Gina Group', 'gina@example.test');
        $this->db->table('group_level_users')->insert(['user_id' => $gl, 'granted_by' => $this->admin, 'granted_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->engine->canAttach($id, $gl)['reason_required']);

        try {
            $this->engine->addDocument($id, $gl, $this->pdfFile('g'), 'g.pdf');
            $this->fail('A reason is mandatory');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('why', $e->getMessage());
        }
        $this->assertSame([], $this->docs($id));

        $this->engine->addDocument($id, $gl, $this->pdfFile('g2'), 'g.pdf', 'Chasing the PO');
        $this->assertSame('group_level', $this->docs($id)[0]['acted_as']);
    }

    public function testARefusedFileLeavesNothingBehind(): void
    {
        $id = $this->inApproval();
        $before = count($this->actions($id));

        try {
            $this->engine->addDocument($id, $this->approver, $this->tempFile('MZ junk'), 'virus.exe');
            $this->fail('exe must be refused');
        } catch (WorkflowException) {
        }

        $this->assertSame([], $this->docs($id));
        $this->assertCount($before, $this->actions($id));
    }

    public function testTheSubmitterIsToldWhenAnApproverAttachesButNotWhenTheyDoItThemselves(): void
    {
        $id = $this->inApproval();
        $count = static fn (self $t): int => $t->db->table('user_notifications')->where('event', 'invoice.document_added')->countAllResults();

        $this->engine->addDocument($id, $this->submitter, $this->pdfFile('s'), 's.pdf');
        $this->assertSame(0, $count($this));

        $this->engine->addDocument($id, $this->approver, $this->pdfFile('a'), 'a.pdf');
        $this->assertSame(1, $count($this));
    }

    public function testAttachingDoesNotResetTheWaitingClock(): void
    {
        $id = $this->inApproval();
        $this->db->table('invoice_actions')->where('invoice_id', $id)->update(['acted_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);

        $this->engine->addDocument($id, $this->approver, $this->pdfFile('a'), 'a.pdf');

        $waiting = (new ApprovalQueue($this->db))->pendingFor($this->approver);
        $this->assertSame(5, (int) $waiting[0]['days_waiting']);
    }

    // -------------------------------------------------------------- SharePoint

    public function testDocumentsAreFiledToSharePointAfterTheInvoicePdf(): void
    {
        $this->connection();
        $id = $this->inApproval();
        $this->engine->addDocument($id, $this->approver, $this->pdfFile('po'), 'Purchase Order.pdf');
        $this->engine->approve($id, $this->approver);

        $sync = new SyncService($this->db, $this->sp);
        $this->assertSame('synced', $sync->process($id));

        $this->assertCount(1, $this->sp->stored);
        $this->assertCount(1, $this->sp->storedFiles);
        $this->assertStringContainsString('_doc' . $this->docs($id)[0]['id'] . '_Purchase_Order.pdf', $this->sp->storedFiles[0]['name']);
        $this->assertSame('application/pdf', $this->sp->storedFiles[0]['mime']);

        $d = $this->docs($id)[0];
        $this->assertSame('synced', $d['sync_status']);
        $this->assertStringStartsWith('https://', (string) $d['sharepoint_url']);
        $this->assertNotNull($d['synced_at']);
    }

    public function testAFailedDocumentIsRetriedWithoutBreakingTheInvoiceSync(): void
    {
        $this->connection();
        $id = $this->inApproval();
        $this->engine->addDocument($id, $this->approver, $this->pdfFile('po'), 'po.pdf');
        $this->engine->approve($id, $this->approver);
        $this->sp->failFiles = 1;

        $sync = new SyncService($this->db, $this->sp);
        $this->assertSame('synced', $sync->process($id), 'the invoice itself is filed');
        $this->assertSame('pending', $this->docs($id)[0]['sync_status']);

        $this->assertSame(['synced' => 1, 'failed' => 0], $sync->syncDocuments());
        $this->assertSame('synced', $this->docs($id)[0]['sync_status']);
        $this->assertSame(['synced' => 0, 'failed' => 0], $sync->syncDocuments(), 'nothing is sent twice');
    }

    public function testNothingIsSentWithoutAConnectionOrBeforeTheInvoiceIsFiled(): void
    {
        $id = $this->inApproval();
        $this->engine->addDocument($id, $this->approver, $this->pdfFile('po'), 'po.pdf');

        $sync = new SyncService($this->db, $this->sp);
        $sync->syncDocuments();
        $this->assertSame([], $this->sp->storedFiles, 'invoice is still in approval');

        $this->engine->approve($id, $this->approver);
        $this->assertSame('not_configured', $sync->process($id));
        $sync->syncDocuments();
        $this->assertSame([], $this->sp->storedFiles, 'no usable connection');
        $this->assertSame('pending', $this->docs($id)[0]['sync_status']);
    }

    // -------------------------------------------------------------- purge

    public function testDocumentsArePurgedLikeTheInvoicePdfButOnlyOnceTheyAreInSharePoint(): void
    {
        $id = $this->inApproval();
        $docId = $this->engine->addDocument($id, $this->approver, $this->pdfFile('po'), 'po.pdf');
        $old   = date('Y-m-d H:i:s', strtotime('-40 days'));
        $this->db->table('invoices')->where('id', $id)->update([
            'status' => 'paid', 'completed_at' => $old, 'ended_at' => $old, 'sync_status' => 'synced', 'synced_at' => $old,
            'sharepoint_item_id' => '42', 'sharepoint_url' => 'https://contoso.sharepoint.com/f.pdf',
        ]);
        $doc  = $this->docs($id)[0];
        $path = (new InvoiceDocuments($this->db))->path($doc);

        $this->assertSame(1, (new FilePurger($this->db))->run(true, 30)['eligible'], 'only the invoice PDF: the document is not in SharePoint');
        (new FilePurger($this->db))->run(false, 30);
        $this->assertFileExists((string) $path);

        $this->db->table('invoice_documents')->where('id', $docId)->update(['sync_status' => 'synced', 'sharepoint_url' => 'https://contoso.sharepoint.com/d.pdf']);
        $r = (new FilePurger($this->db))->run(false, 30);

        $this->assertSame(1, $r['deleted']);
        $this->assertFileDoesNotExist((string) $path);
        $this->assertNotNull($this->docs($id)[0]['purged_at']);
        $this->assertNull((new InvoiceDocuments($this->db))->path($this->docs($id)[0]));
    }

    public function testDocumentsOfARejectedInvoiceArePurgedAfterRetention(): void
    {
        $id = $this->inApproval();
        $this->engine->addDocument($id, $this->approver, $this->pdfFile('po'), 'po.pdf');
        $this->engine->reject($id, $this->approver, 'No');
        $this->db->table('invoices')->where('id', $id)->update(['ended_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);

        $this->assertSame(2, (new FilePurger($this->db))->run(false, 30)['deleted'], 'the invoice PDF and the document');
    }
}
