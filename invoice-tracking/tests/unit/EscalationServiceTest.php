<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminAccess;
use App\Services\EscalationService;
use App\Services\InvoiceFiles;
use App\Services\WorkflowEngine;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;

/**
 * @internal
 */
final class EscalationServiceTest extends AppTestCase
{
    private FakeMailTransport $mail;
    private WorkflowEngine $engine;
    private EscalationService $svc;
    private int $acme;
    private int $sue;
    private int $alan;
    private int $gary;
    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new FakeMailTransport();
        Services::injectMock('mailer', $this->mail);
        $this->engine = new WorkflowEngine($this->db);
        $this->svc    = new EscalationService($this->db);

        $admin      = $this->makeUser('Admin', true);
        $this->acme = $this->makeCompany('ACME');
        $this->sue  = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $this->alan = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $this->gary = $this->makeUserWithEmail('Gary', 'gary@example.test');
        $this->join($this->sue, $this->acme, true);
        $this->join($this->alan, $this->acme);
        (new AdminAccess($this->db))->grantGroupLevel($admin, $this->gary);

        $this->type = $this->makeRoute($this->acme, [
            ['type' => 'submit', 'user' => $this->sue, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->alan, 'role' => 'Accountant', 'label' => 'Alan', 'sla' => 2],
        ])['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    private function submit(): int
    {
        $id = $this->engine->createDraft($this->sue, [
            'company_id' => $this->acme, 'invoice_type_id' => $this->type, 'supplier' => 'Supplier', 'invoice_number' => 'E1',
            'invoice_date' => '2026-01-01', 'due_date' => '2026-12-01', 'amount' => '100',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->sue, $this->pdfFile(), 'f.pdf');
        $this->engine->submit($id, $this->sue);

        return $id;
    }

    private function eventsFor(int $userId, string $event): int
    {
        return $this->db->table('user_notifications')->where(['user_id' => $userId, 'event' => $event])->countAllResults();
    }

    public function testNothingHappensBeforeTheLimit(): void
    {
        $this->submit();

        $r = $this->svc->run(time() + 86400);

        $this->assertSame(['checked' => 1, 'reminders' => 0, 'escalations' => 0], $r);
        $this->assertSame(0, $this->eventsFor($this->alan, 'invoice.reminder'));
    }

    public function testApproverIsRemindedOnceAfterTheLimit(): void
    {
        $this->submit();

        $first  = $this->svc->run(time() + 2 * 86400 + 60);
        $second = $this->svc->run(time() + 3 * 86400);

        $this->assertSame(1, $first['reminders']);
        $this->assertSame(0, $first['escalations']);
        $this->assertSame(0, $second['reminders']);
        $this->assertSame(1, $this->eventsFor($this->alan, 'invoice.reminder'));
        $this->assertSame(0, $this->eventsFor($this->gary, 'invoice.escalated'));
        $reminders = array_filter($this->mail->to('alan@example.test'), static fn ($m): bool => str_starts_with($m->subject, 'Reminder:'));
        $this->assertCount(1, $reminders);
    }

    public function testGroupLevelUsersAreToldAtTwiceTheLimitAndOnlyOnce(): void
    {
        $this->submit();

        $r1 = $this->svc->run(time() + 4 * 86400 + 60);
        $r2 = $this->svc->run(time() + 5 * 86400);

        $this->assertSame(1, $r1['reminders']);
        $this->assertSame(1, $r1['escalations']);
        $this->assertSame(0, $r2['escalations']);
        $this->assertSame(1, $this->eventsFor($this->gary, 'invoice.escalated'));
        $this->assertSame(0, $this->eventsFor($this->sue, 'invoice.escalated'));
    }

    public function testStepsWithoutALimitAreNeverChased(): void
    {
        $this->db->table('route_steps')->update(['sla_days' => null]);
        $this->submit();

        $r = $this->svc->run(time() + 90 * 86400);

        $this->assertSame(['checked' => 0, 'reminders' => 0, 'escalations' => 0], $r);
    }

    public function testFinishedInvoicesAreIgnored(): void
    {
        $id = $this->submit();
        $this->engine->approve($id, $this->alan);

        $this->assertSame(0, $this->svc->run(time() + 90 * 86400)['checked']);
    }

    public function testReturnedAndResubmittedInvoiceIsTrackedAfresh(): void
    {
        $id = $this->submit();
        $this->svc->run(time() + 2 * 86400 + 60);

        $this->engine->returnForChanges($id, $this->alan, 'Fix the PO');
        $this->engine->resubmit($id, $this->sue, 'Fixed the PO');
        // Move the whole history into the past (original submit 10 days ago, resubmit 3 days ago).
        $this->db->table('invoice_actions')->where('invoice_id', $id)->where('action', 'submit')->update(['acted_at' => date('Y-m-d H:i:s', time() - 10 * 86400)]);
        $this->db->table('invoice_actions')->where('invoice_id', $id)->where('action', 'resubmit')->update(['acted_at' => date('Y-m-d H:i:s', time() - 3 * 86400)]);

        $r = $this->svc->run();

        $this->assertSame(1, $r['reminders']);
        $this->assertSame(2, $this->eventsFor($this->alan, 'invoice.reminder'));
    }

    public function testRouteSnapshotCarriesTheLimit(): void
    {
        $id  = $this->submit();
        $inv = $this->db->table('invoices')->where('id', $id)->get()->getRowArray();

        $this->assertSame(2, $this->engine->currentStep($inv)['sla_days']);

        // Editing the route afterwards does not change an invoice already in flight.
        $this->db->table('route_steps')->update(['sla_days' => 30]);
        $this->assertSame(2, $this->engine->currentStep($inv)['sla_days']);
    }

    public function testInactiveGroupLevelUsersAreSkipped(): void
    {
        $this->db->table('users')->where('id', $this->gary)->update(['active' => 0]);
        $this->submit();

        $this->svc->run(time() + 10 * 86400);

        $this->assertSame(0, $this->eventsFor($this->gary, 'invoice.escalated'));
    }
}
