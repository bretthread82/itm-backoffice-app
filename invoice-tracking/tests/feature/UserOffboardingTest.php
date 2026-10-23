<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\UserOffboarding;
use RuntimeException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class UserOffboardingTest extends AppTestCase
{
    private int $admin;
    private int $leaver;
    private int $heir;
    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = $this->makeUserWithEmail('Admin', 'admin@example.test', true);
        $this->leaver  = $this->makeUserWithEmail('Leaver', 'leaver@example.test');
        $this->heir    = $this->makeUserWithEmail('Heir', 'heir@example.test');
        $this->company = $this->makeCompany('ACME');
        $this->join($this->leaver, $this->company, true);
        $this->join($this->heir, $this->company, true);
    }

    /** In-flight invoice at step 2 of 3; the leaver owns steps 1 (done) and 2, someone else owns 3. */
    private function inFlight(): int
    {
        $id    = $this->makeInvoice($this->company, $this->leaver);
        $steps = [
            ['order' => 1, 'step_type' => 'approve', 'role' => 'A', 'assignee_kind' => 'user', 'user_id' => $this->leaver, 'label' => '', 'email' => null],
            ['order' => 2, 'step_type' => 'approve', 'role' => 'B', 'assignee_kind' => 'user', 'user_id' => $this->leaver, 'label' => '', 'email' => null],
            ['order' => 3, 'step_type' => 'approve', 'role' => 'C', 'assignee_kind' => 'user', 'user_id' => $this->admin, 'label' => '', 'email' => null],
        ];
        $this->db->table('invoices')->where('id', $id)->update(['route_snapshot' => json_encode(['steps' => $steps]), 'current_step_order' => 2]);

        return $id;
    }

    /** @return list<int> user ids per step */
    private function owners(int $invoiceId): array
    {
        $snap = json_decode((string) $this->invoiceRow($invoiceId)['route_snapshot'], true);

        return array_map(static fn (array $s): int => (int) $s['user_id'], $snap['steps']);
    }

    public function testTransfersFutureStepsRoutesAndSubmittedInvoicesAndDisables(): void
    {
        $invoice = $this->inFlight();
        $this->makeRoute($this->company, [['user' => $this->leaver, 'role' => 'A'], ['user' => $this->admin, 'role' => 'B']]);
        $this->db->table('delegations')->insert([
            'delegator_id' => $this->leaver, 'delegate_id' => $this->heir, 'starts_at' => '2020-01-01 00:00:00', 'ends_at' => '2099-01-01 00:00:00', 'active' => 1,
        ]);
        $done = $this->makeInvoice($this->company, $this->leaver, 'paid');

        $r = (new UserOffboarding())->transfer($this->admin, $this->leaver, $this->heir, true);

        // Step 1 was already behind the invoice: history stays with the leaver.
        $this->assertSame([$this->leaver, $this->heir, $this->admin], $this->owners($invoice));
        $this->assertSame(1, $r['steps']);
        $this->assertSame(1, $this->db->table('route_steps')->where('assignee_user_id', $this->heir)->countAllResults());
        $this->assertSame(0, $this->db->table('route_steps')->where('assignee_user_id', $this->leaver)->countAllResults());
        $this->assertSame($this->heir, $this->invoiceRow($invoice)['submitted_by']);
        $this->assertSame($this->leaver, $this->invoiceRow($done)['submitted_by']);
        $this->assertSame(0, $this->db->table('delegations')->where('active', 1)->countAllResults());
        $this->assertSame(0, $this->db->table('users')->where('id', $this->leaver)->get()->getRowArray()['active']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'user.work_transferred')->countAllResults());
        $this->assertSame(1, $this->db->table('invoice_notes')->where('invoice_id', $invoice)->countAllResults());
    }

    public function testReplacementCanNowActOnTheInvoice(): void
    {
        $invoice = $this->inFlight();
        (new UserOffboarding())->transfer($this->admin, $this->leaver, $this->heir, false);

        $engine = new \App\Services\WorkflowEngine();
        $this->assertSame('normal', $engine->canAct($invoice, $this->heir)['as']);
        $this->assertFalse($engine->canAct($invoice, $this->leaver)['can_act']);
    }

    public function testRefusesReplacementWhoCannotSeeTheCompany(): void
    {
        $this->inFlight();
        $outsider = $this->makeUserWithEmail('Outsider', 'out@example.test');

        try {
            (new UserOffboarding())->transfer($this->admin, $this->leaver, $outsider, true);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ACME', $e->getMessage());
        }
        $this->assertSame(1, $this->db->table('users')->where('id', $this->leaver)->get()->getRowArray()['active']);
        $this->assertSame([$this->leaver, $this->leaver, $this->admin], $this->owners($this->db->table('invoices')->get()->getRowArray()['id']));
    }

    public function testNonAdminIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        (new UserOffboarding())->transfer($this->heir, $this->leaver, $this->admin, false);
    }

    public function testDisablingTheLastAdminRollsEverythingBack(): void
    {
        $this->join($this->admin, $this->company);
        $invoice = $this->makeInvoice($this->company, $this->admin, 'draft');

        try {
            (new UserOffboarding())->transfer($this->admin, $this->admin, $this->heir, true);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('last active administrator', $e->getMessage());
        }
        $this->assertSame($this->admin, $this->invoiceRow($invoice)['submitted_by']);
    }
}
