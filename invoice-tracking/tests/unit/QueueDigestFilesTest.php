<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminAccess;
use App\Services\ApprovalQueue;
use App\Services\DelegationResolver;
use App\Services\DigestService;
use App\Services\InvoiceFiles;
use App\Services\NotificationService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowException;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;

/**
 * @internal
 */
final class QueueDigestFilesTest extends AppTestCase
{
    private FakeMailTransport $mail;
    private WorkflowEngine $engine;
    private int $acme;
    private int $beta;
    private int $sue;
    private int $alan;
    private int $maria;
    private int $gary;
    private int $typeAcme;
    private int $typeBeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new FakeMailTransport();
        Services::injectMock('mailer', $this->mail);
        $this->engine = new WorkflowEngine($this->db);

        $admin    = $this->makeUser('Admin', true);
        $this->acme = $this->makeCompany('ACME');
        $this->beta = $this->makeCompany('BETA');
        $this->sue   = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $this->alan  = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $this->maria = $this->makeUserWithEmail('Maria', 'maria@example.test');
        $this->gary  = $this->makeUserWithEmail('Gary', 'gary@example.test');
        $this->join($this->sue, $this->acme, true);
        $this->join($this->sue, $this->beta, true);
        $this->join($this->alan, $this->acme);
        $this->join($this->maria, $this->beta);
        (new AdminAccess($this->db))->grantGroupLevel($admin, $this->gary);

        $this->typeAcme = $this->makeRoute($this->acme, [
            ['type' => 'submit', 'user' => $this->sue, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->alan, 'role' => 'Accountant', 'label' => 'Alan'],
        ])['type'];
        $this->typeBeta = $this->makeRoute($this->beta, [
            ['type' => 'submit', 'user' => $this->sue, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->maria, 'role' => 'Manager', 'label' => 'Maria'],
        ])['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        setting()->forget('AppSettings.maxUploadMb');
        parent::tearDown();
    }

    private function submitFor(int $companyId, int $typeId, string $no, string $due = '2026-12-01'): int
    {
        $id = $this->engine->createDraft($this->sue, [
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'supplier' => 'Supplier ' . $no, 'invoice_number' => $no,
            'invoice_date' => date('Y-m-d', strtotime($due) - 30 * 86400), 'due_date' => $due, 'amount' => '100',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->sue, $this->pdfFile(), 'f.pdf');
        $this->engine->submit($id, $this->sue);

        return $id;
    }

    // -------------------------------------------------------------- queue

    public function testPendingForShowsOnlyMyStepsAndAreScoped(): void
    {
        $a = $this->submitFor($this->acme, $this->typeAcme, 'A1');
        $b = $this->submitFor($this->beta, $this->typeBeta, 'B1');
        $queue = new ApprovalQueue($this->db);

        $this->assertSame([$a], array_map('intval', array_column($queue->pendingFor($this->alan), 'id')));
        $this->assertSame([$b], array_map('intval', array_column($queue->pendingFor($this->maria), 'id')));
        $this->assertSame([], $queue->pendingFor($this->sue));
    }

    public function testGroupLevelUserHasNoPersonalQueueButSeesEverythingInAwaitingAll(): void
    {
        $this->submitFor($this->acme, $this->typeAcme, 'A1');
        $this->submitFor($this->beta, $this->typeBeta, 'B1');
        $queue = new ApprovalQueue($this->db);

        $this->assertSame([], $queue->pendingFor($this->gary));
        $this->assertCount(2, $queue->awaitingAll($this->gary));
        $this->assertCount(1, $queue->awaitingAll($this->alan)); // scoped to ACME
    }

    public function testDelegateSeesTheDelegatedItemsInTheirQueue(): void
    {
        $this->join($this->maria, $this->acme);
        (new DelegationResolver($this->db))->create($this->alan, $this->alan, $this->maria, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);
        $a = $this->submitFor($this->acme, $this->typeAcme, 'A1');

        $items = (new ApprovalQueue($this->db))->pendingFor($this->maria);
        $this->assertSame([$a], array_map('intval', array_column($items, 'id')));
        $this->assertSame('delegate', $items[0]['acting_as']);
    }

    public function testMineGroupsMyInvoicesByWhatTheyNeed(): void
    {
        $waiting = $this->submitFor($this->acme, $this->typeAcme, 'W1');
        $back    = $this->submitFor($this->acme, $this->typeAcme, 'R1');
        $this->engine->returnForChanges($back, $this->alan, 'Fix it');
        $draft = $this->engine->createDraft($this->sue, ['company_id' => $this->acme, 'invoice_type_id' => $this->typeAcme]);

        $mine = (new ApprovalQueue($this->db))->mine($this->sue);
        $this->assertSame([$waiting], array_map('intval', array_column($mine['waiting'], 'id')));
        $this->assertSame([$back], array_map('intval', array_column($mine['needs_action'], 'id')));
        $this->assertSame([$draft], array_map('intval', array_column($mine['drafts'], 'id')));
    }

    public function testDaysWaitingAndPastDueAreCalculated(): void
    {
        $id = $this->submitFor($this->acme, $this->typeAcme, 'OLD', date('Y-m-d', time() - 40 * 86400));
        $this->db->table('invoice_actions')->where('invoice_id', $id)->update(['acted_at' => date('Y-m-d H:i:s', time() - 5 * 86400 - 60)]);

        $item = (new ApprovalQueue($this->db))->pendingFor($this->alan)[0];
        $this->assertSame(5, $item['days_waiting']);
        $this->assertGreaterThanOrEqual(39, $item['days_past_due']);
    }

    // -------------------------------------------------------------- digest

    public function testDigestSendsOnePerUserAndNothingToPeopleWithNothingOutstanding(): void
    {
        $this->submitFor($this->acme, $this->typeAcme, 'A1');
        $this->mail->sent = []; // ignore the immediate alerts
        $this->db->table('user_notifications')->emptyTable();

        $result = (new DigestService($this->db))->run();

        $this->assertSame(2, $result['sent']); // Alan (waiting on him) and Sue (her own invoice is in approval)
        $this->assertCount(1, $this->mail->to('alan@example.test'));
        $this->assertCount(1, $this->mail->to('sue@example.test'));
        $this->assertCount(0, $this->mail->to('maria@example.test')); // nothing outstanding
        $this->assertCount(0, $this->mail->to('gary@example.test'));
        $this->assertStringContainsString('Waiting for your approval', $this->mail->to('alan@example.test')[0]->html);
        $this->assertStringContainsString('#A1', $this->mail->to('alan@example.test')[0]->html);
        $this->assertSame(1, $this->db->table('user_notifications')->where(['user_id' => $this->alan, 'event' => 'digest'])->countAllResults());
    }

    public function testRunningTheDigestTwiceInADayDoesNotRepeat(): void
    {
        $this->submitFor($this->acme, $this->typeAcme, 'A1');
        $digest = new DigestService($this->db);

        $first  = $digest->run();
        $before = count($this->mail->sent);
        $second = $digest->run();

        $this->assertGreaterThan(0, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertCount($before, $this->mail->sent);
    }

    public function testDigestPreferenceCanSwitchItOff(): void
    {
        $this->submitFor($this->acme, $this->typeAcme, 'A1');
        $this->db->table('notification_prefs')->insert(['user_id' => $this->alan, 'event' => 'digest', 'email' => 1, 'daily_digest' => 0]);
        $this->db->table('user_notifications')->where('event', 'digest')->delete();
        $this->mail->sent = [];

        (new DigestService($this->db))->run();

        $this->assertCount(0, $this->mail->to('alan@example.test'));
        $this->assertSame(0, $this->db->table('user_notifications')->where(['user_id' => $this->alan, 'event' => 'digest'])->countAllResults());
    }

    public function testDigestMentionsDaysPastDue(): void
    {
        $id = $this->submitFor($this->acme, $this->typeAcme, 'LATE', date('Y-m-d', time() - 70 * 86400));
        $this->assertGreaterThan(0, $id);
        $this->mail->sent = [];

        (new DigestService($this->db))->run();

        $this->assertStringContainsString('days past due', $this->mail->to('alan@example.test')[0]->html);
    }

    // -------------------------------------------------------------- bell

    public function testBellCountsMarksReadOnlyForTheOwnerAndMarksAll(): void
    {
        $this->submitFor($this->acme, $this->typeAcme, 'A1'); // Alan: submitted, Sue: received
        $svc = new NotificationService($this->db);

        $this->assertSame(1, $svc->unreadCount($this->alan));
        $latest = $svc->latest($this->alan);
        $this->assertSame('invoices/' . $latest[0]['invoice_id'], $latest[0]['url']);

        $svc->markRead($this->maria, (int) $latest[0]['id']); // someone else's id: ignored
        $this->assertSame(1, $svc->unreadCount($this->alan));

        $svc->markRead($this->alan, (int) $latest[0]['id']);
        $this->assertSame(0, $svc->unreadCount($this->alan));

        $svc->markAllRead($this->sue);
        $this->assertSame(0, $svc->unreadCount($this->sue));
    }

    // -------------------------------------------------------------- PDFs

    public function testOnlyRealPdfsAreAccepted(): void
    {
        $files = new InvoiceFiles($this->db);
        $good  = $this->pdfFile();

        $files->validate($good, 'ok.pdf');
        $this->addToAssertionCount(1);

        $bad = [
            'wrong extension' => [$good, 'invoice.txt'],
            'not a pdf'       => [tap_text(), 'fake.pdf'],
            'empty'           => [tap_empty(), 'empty.pdf'],
        ];
        foreach ($bad as $label => [$path, $name]) {
            try {
                $files->validate($path, $name);
                $this->fail("Should have rejected: {$label}");
            } catch (WorkflowException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOversizeFilesAreRejectedUsingTheSetting(): void
    {
        setting()->set('AppSettings.maxUploadMb', 1);
        $path = $this->pdfFile();
        file_put_contents($path, str_repeat('x', 1_200_000), FILE_APPEND);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('larger than 1 MB');
        (new InvoiceFiles($this->db))->validate($path, 'big.pdf');
    }

    public function testStoringAReplacementKeepsTheOldFileButMarksItNotCurrent(): void
    {
        $id    = $this->engine->createDraft($this->sue, ['company_id' => $this->acme, 'invoice_type_id' => $this->typeAcme]);
        $files = new InvoiceFiles($this->db);

        $first  = $files->store($id, $this->sue, $this->pdfFile('one'), 'first.pdf');
        $second = $files->store($id, $this->sue, $this->pdfFile('two'), "..\\evil/name.pdf");

        $this->assertSame($second, (int) $files->current($id)['id']);
        $this->assertSame(0, (int) $this->db->table('invoice_files')->where('id', $first)->get()->getRow()->is_current);
        $row = $files->current($id);
        $this->assertStringNotContainsString('/', $row['original_name']);
        $this->assertStringNotContainsString('\\', $row['original_name']);
        $this->assertSame(64, strlen($row['sha256']));
        $this->assertNotNull($files->path($row));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $row['stored_name']); // random name, never the upload's
    }
}

/** @internal helpers for the PDF validation test */
function tap_text(): string
{
    $p = tempnam(sys_get_temp_dir(), 'txt') . '.pdf';
    file_put_contents($p, 'this is plain text, not a PDF');
    register_shutdown_function(static fn () => @unlink($p));

    return $p;
}

function tap_empty(): string
{
    $p = tempnam(sys_get_temp_dir(), 'emp') . '.pdf';
    file_put_contents($p, '');
    register_shutdown_function(static fn () => @unlink($p));

    return $p;
}
