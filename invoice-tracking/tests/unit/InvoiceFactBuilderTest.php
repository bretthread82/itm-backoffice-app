<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\InvoiceFactBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class InvoiceFactBuilderTest extends CIUnitTestCase
{
    private const NOW = '2026-10-20 12:00:00';

    /** @return array<string, mixed> */
    private function invoice(array $over = [], ?array $steps = null): array
    {
        $steps ??= [
            ['order' => 1, 'step_type' => 'submit', 'assignee_kind' => 'user', 'user_id' => 1, 'role' => 'Clerk', 'label' => 'Sue'],
            ['order' => 2, 'step_type' => 'approve', 'assignee_kind' => 'user', 'user_id' => 2, 'team_id' => null, 'role' => 'Manager', 'label' => 'Alan', 'sla_days' => 1],
            ['order' => 3, 'step_type' => 'process', 'assignee_kind' => 'team', 'user_id' => null, 'team_id' => 9, 'role' => 'AP', 'label' => 'AP team'],
            ['order' => 4, 'step_type' => 'pay', 'assignee_kind' => 'user', 'user_id' => 4, 'role' => 'Pay', 'label' => 'Penny'],
        ];

        return $over + [
            'id' => 10, 'company_id' => 5, 'invoice_type_id' => 7, 'supplier' => ' Acme Ltd ', 'currency' => 'USD', 'amount' => '100.00',
            'status' => 'paid', 'current_step_order' => null, 'high_priority' => 1, 'extra_approval' => 0,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'submitted_at' => '2026-09-10 09:00:00',
            'completed_at' => '2026-09-12 12:00:00', 'processed_at' => '2026-09-11 11:00:00', 'paid_at' => '2026-09-12 12:00:00', 'ended_at' => '2026-09-12 12:00:00',
            'route_snapshot' => json_encode(['steps' => $steps]),
        ];
    }

    /** @return array<string, mixed> */
    private function act(int $id, string $action, ?int $step, string $at, string $as = 'normal', int $by = 2): array
    {
        return ['id' => $id, 'action' => $action, 'step_order' => $step, 'acted_at' => $at, 'acted_as' => $as, 'acted_by' => $by, 'acted_by_name' => 'User ' . $by];
    }

    public function testHappyPathBuildsOneVisitPerStepWithDurationsAndSla(): void
    {
        $r = (new InvoiceFactBuilder())->build($this->invoice(), [
            $this->act(1, 'submit', null, '2026-09-10 09:00:00', 'normal', 1),
            $this->act(2, 'approve', 2, '2026-09-11 09:00:00'),   // 24h at a 1-day SLA step: breached
            $this->act(3, 'approve', 3, '2026-09-11 11:00:00'),
            $this->act(4, 'approve', 4, '2026-09-12 12:00:00', 'normal', 4),
        ], [], 'ACME', self::NOW);

        $steps = $r['steps'];
        $this->assertCount(3, $steps);
        $this->assertSame([2, 3, 4], array_column($steps, 'step_order'));
        $this->assertSame([24.0, 2.0, 25.0], array_map('floatval', array_column($steps, 'duration_hours')));
        $this->assertSame(['approved', 'approved', 'approved'], array_column($steps, 'outcome'));
        $this->assertSame([1, 0, 0], array_column($steps, 'sla_breached'));
        $this->assertSame('Manager (Alan)', $steps[0]['assignee_label']);
        $this->assertSame(9, $steps[1]['team_id']);

        $f = $r['fact'];
        $this->assertSame('ACME', $f['supplier_code']);
        $this->assertSame('Acme Ltd', $f['supplier_name']);
        $this->assertSame(0, $f['outstanding']);
        $this->assertSame(3, $f['step_count']);
        $this->assertSame(2.13, $f['days_to_paid']);
        $this->assertSame(1.08, $f['days_to_processed']);
        $this->assertSame(1, $f['on_time'], 'completed 12 Sep, due 1 Oct: on time');
    }

    public function testOnTimeIsFlaggedOnlyWhenCompletedByTheDueDate(): void
    {
        $late = (new InvoiceFactBuilder())->build($this->invoice(['due_date' => '2026-09-01']), [], [], null, self::NOW)['fact'];
        $this->assertSame(0, $late['on_time']);
        $ok = (new InvoiceFactBuilder())->build($this->invoice(['due_date' => '2026-09-12']), [], [], null, self::NOW)['fact'];
        $this->assertSame(1, $ok['on_time']);
        $none = (new InvoiceFactBuilder())->build($this->invoice(['completed_at' => null, 'status' => 'in_approval']), [], [], null, self::NOW)['fact'];
        $this->assertNull($none['on_time']);
    }

    public function testReturnAndResubmitStartANewCycle(): void
    {
        $inv = $this->invoice(['status' => 'in_approval', 'current_step_order' => 2, 'completed_at' => null, 'processed_at' => null, 'paid_at' => null, 'ended_at' => null, 'submitted_at' => '2026-09-14 09:00:00']);
        $r = (new InvoiceFactBuilder())->build($inv, [
            $this->act(1, 'submit', null, '2026-09-10 09:00:00', 'normal', 1),
            $this->act(2, 'return', 2, '2026-09-11 09:00:00'),
            $this->act(3, 'resubmit', null, '2026-09-14 09:00:00', 'normal', 1),
        ], [], null, '2026-09-14 10:00:00');

        $this->assertSame(['returned', 'open'], array_column($r['steps'], 'outcome'));
        $this->assertSame([1, 2], array_column($r['steps'], 'cycle'));
        $this->assertSame(1, $r['fact']['return_count']);
        $this->assertSame(1, $r['fact']['resubmit_count']);
        $this->assertSame('2026-09-10 09:00:00', $r['fact']['first_submitted_at']);
        $this->assertSame(1, $r['fact']['outstanding']);
        $this->assertNull($r['steps'][1]['left_at']);
    }

    public function testReturnToPreviousApproverReopensTheEarlierStep(): void
    {
        $steps = [
            ['order' => 1, 'step_type' => 'submit', 'assignee_kind' => 'user'],
            ['order' => 2, 'step_type' => 'approve', 'assignee_kind' => 'user', 'user_id' => 2],
            ['order' => 3, 'step_type' => 'approve', 'assignee_kind' => 'user', 'user_id' => 3],
        ];
        $inv = $this->invoice(['status' => 'in_approval', 'current_step_order' => 2, 'completed_at' => null], $steps);
        $r = (new InvoiceFactBuilder())->build($inv, [
            $this->act(1, 'submit', null, '2026-09-10 09:00:00', 'normal', 1),
            $this->act(2, 'approve', 2, '2026-09-10 10:00:00'),
            $this->act(3, 'return_previous', 3, '2026-09-10 12:00:00', 'normal', 3),
        ], [], null, '2026-09-10 13:00:00');

        $this->assertSame([2, 3, 2], array_column($r['steps'], 'step_order'));
        $this->assertSame(['approved', 'returned_previous', 'open'], array_column($r['steps'], 'outcome'));
        $this->assertSame(1, $r['fact']['return_count']);
    }

    public function testGroupLevelActionsCountAsOverrides(): void
    {
        $r = (new InvoiceFactBuilder())->build($this->invoice(), [
            $this->act(1, 'submit', null, '2026-09-10 09:00:00', 'normal', 1),
            $this->act(2, 'approve', 2, '2026-09-10 10:00:00', 'group_level', 99),
            $this->act(3, 'approve', 3, '2026-09-10 11:00:00'),
            $this->act(4, 'approve', 4, '2026-09-10 12:00:00'),
        ], [], null, self::NOW);

        $this->assertSame(1, $r['fact']['override_count']);
        $this->assertSame([1, 0, 0], array_column($r['steps'], 'override'));
    }

    public function testOpenVisitBreachesSlaAndPicksUpTheHighestChase(): void
    {
        $inv = $this->invoice(['status' => 'in_approval', 'current_step_order' => 2, 'completed_at' => null]);
        $r = (new InvoiceFactBuilder())->build($inv, [
            $this->act(1, 'submit', null, '2026-10-17 09:00:00', 'normal', 1),
        ], [
            ['step_order' => 2, 'step_entered_at' => '2026-10-17 09:00:00', 'level' => 'reminder'],
            ['step_order' => 2, 'step_entered_at' => '2026-10-17 09:00:00', 'level' => 'escalation'],
            ['step_order' => 3, 'step_entered_at' => '2026-10-17 09:00:00', 'level' => 'escalation'],
        ], null, self::NOW);

        $this->assertCount(1, $r['steps']);
        $this->assertSame('open', $r['steps'][0]['outcome']);
        $this->assertSame(1, $r['steps'][0]['sla_breached']);
        $this->assertSame('escalation', $r['steps'][0]['escalation_level']);
        $this->assertNull($r['steps'][0]['duration_hours']);
    }

    public function testCancelledInvoiceKeepsNoOpenVisit(): void
    {
        $inv = $this->invoice(['status' => 'cancelled', 'current_step_order' => null, 'completed_at' => null, 'ended_at' => '2026-09-12 12:00:00']);
        $r = (new InvoiceFactBuilder())->build($inv, [
            $this->act(1, 'submit', null, '2026-09-10 09:00:00', 'normal', 1),
            $this->act(2, 'reject', 2, '2026-09-10 10:00:00'),
            $this->act(3, 'cancel', null, '2026-09-12 12:00:00', 'normal', 1),
        ], [], null, self::NOW);

        $this->assertSame(['rejected'], array_column($r['steps'], 'outcome'));
        $this->assertSame(0, $r['fact']['outstanding']);
    }

    public function testClaimsAndAttachmentsAreNotStepTransitions(): void
    {
        $inv = $this->invoice(['status' => 'in_approval', 'current_step_order' => 2, 'completed_at' => null]);
        $r = (new InvoiceFactBuilder())->build($inv, [
            $this->act(1, 'submit', null, '2026-09-10 09:00:00', 'normal', 1),
            $this->act(2, 'claim', 2, '2026-09-10 10:00:00'),
            $this->act(3, 'attach', null, '2026-09-10 11:00:00'),
        ], [], null, '2026-09-10 12:00:00');

        $this->assertCount(1, $r['steps']);
        $this->assertSame('2026-09-10 09:00:00', $r['steps'][0]['entered_at'], 'not reset by a claim or attachment');
    }
}
