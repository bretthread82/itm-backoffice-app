<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\SplitProcessedAndPaid;
use ReflectionMethod;
use Tests\Support\AppTestCase;

/**
 * The data steps of the processed/paid split (the columns already exist: PHPUnit runs every migration).
 *
 * @internal
 */
final class SplitProcessedAndPaidMigrationTest extends AppTestCase
{
    private function invoke(string $method): void
    {
        $migration = new SplitProcessedAndPaid();
        $m         = new ReflectionMethod($migration, $method);
        $m->invoke($migration);
    }

    public function testCompletedInvoicesBecomePaidWhenAPaymentWasRecordedAndProcessedOtherwise(): void
    {
        $company = $this->makeCompany('ACME');
        $user    = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $when    = '2026-09-30 10:00:00';

        $withPayment = $this->makeInvoice($company, $user, 'completed');
        $without     = $this->makeInvoice($company, $user, 'completed');
        $open        = $this->makeInvoice($company, $user, 'in_approval');
        $this->db->table('invoices')->where('id', $withPayment)->update(['completed_at' => $when, 'payment_reference' => 'R1', 'payment_date' => '2026-09-29']);
        $this->db->table('invoices')->where('id', $without)->update(['completed_at' => $when]);

        $this->invoke('convertStatuses');
        $this->invoke('convertStatuses');   // safe to run again

        $paid = $this->db->table('invoices')->where('id', $withPayment)->get()->getRowArray();
        $this->assertSame('paid', $paid['status']);
        $this->assertSame($when, $paid['paid_at']);
        $this->assertSame($when, $paid['processed_at']);

        $proc = $this->db->table('invoices')->where('id', $without)->get()->getRowArray();
        $this->assertSame('processed', $proc['status']);
        $this->assertNull($proc['paid_at']);

        $this->assertSame('in_approval', $this->db->table('invoices')->where('id', $open)->get()->getRow()->status);
    }

    public function testRoutesEndingInAProcessStepGetAPayStepForTheSameAssigneeOnce(): void
    {
        $company = $this->makeCompany('ACME');
        $a       = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $p       = $this->makeUserWithEmail('Pat', 'pat@example.test');

        $ends    = $this->makeRoute($company, [['type' => 'approve', 'user' => $a], ['type' => 'process', 'user' => $p, 'role' => 'AP']], 'EndsInProcess');
        $approve = $this->makeRoute($company, [['type' => 'approve', 'user' => $a]], 'EndsInApprove');
        $has     = $this->makeRoute($company, [['type' => 'process', 'user' => $p], ['type' => 'pay', 'user' => $p]], 'HasPay');

        $this->invoke('addPayStepsToRoutes');
        $this->invoke('addPayStepsToRoutes');   // safe to run again

        $steps = $this->db->table('route_steps')->where('route_id', $ends['route'])->orderBy('step_order')->get()->getResultArray();
        $this->assertSame(['approve', 'process', 'pay'], array_column($steps, 'step_type'));
        $this->assertSame((int) $p, (int) $steps[2]['assignee_user_id']);
        $this->assertSame($steps[1]['job_title_id'], $steps[2]['job_title_id']);
        $this->assertSame(3, (int) $steps[2]['step_order']);

        $this->assertSame(1, $this->db->table('route_steps')->where('route_id', $approve['route'])->countAllResults());
        $this->assertSame(2, $this->db->table('route_steps')->where('route_id', $has['route'])->countAllResults());
    }

    public function testInFlightSnapshotsGainThePayStepToo(): void
    {
        $company = $this->makeCompany('ACME');
        $user    = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $id      = $this->makeInvoice($company, $user, 'approved');
        $snap    = ['route_id' => 1, 'version' => 1, 'variant' => 'default', 'steps' => [
            ['order' => 1, 'step_type' => 'approve', 'role' => 'A', 'assignee_kind' => 'user', 'user_id' => 5, 'team_id' => null, 'label' => 'Al', 'email' => null, 'sla_days' => null],
            ['order' => 2, 'step_type' => 'process', 'role' => 'AP', 'assignee_kind' => 'user', 'user_id' => 6, 'team_id' => null, 'label' => 'Pat', 'email' => null, 'sla_days' => 3],
        ]];
        $this->db->table('invoices')->where('id', $id)->update(['route_snapshot' => json_encode($snap), 'current_step_order' => 2]);

        $this->invoke('addPayStepsToSnapshots');
        $this->invoke('addPayStepsToSnapshots');   // safe to run again

        $steps = json_decode((string) $this->db->table('invoices')->where('id', $id)->get()->getRow()->route_snapshot, true)['steps'];
        $this->assertSame(['approve', 'process', 'pay'], array_column($steps, 'step_type'));
        $this->assertSame(3, $steps[2]['order']);
        $this->assertSame(6, $steps[2]['user_id']);
        $this->assertSame(3, $steps[2]['sla_days']);
    }
}
