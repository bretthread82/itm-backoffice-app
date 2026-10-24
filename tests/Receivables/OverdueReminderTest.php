<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\Collections;
use App\Core\Receivables\OverdueReminder;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * Overdue is derived, never stored, and reminders go out once per interval however often the command runs.
 *
 * @internal
 */
final class OverdueReminderTest extends ReceivablesTestCase
{
    use StreamFilterTrait;

    private int $company;
    private int $raiser;
    private int $collector;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        setting()->set('ReceivablesSettings.remindEveryDays', 7);
        $this->company   = $this->makeCompany('ACME');
        $this->raiser    = $this->makeUserNamed('Rae Raiser');
        $this->collector = $this->makeUserNamed('Cal Collector');
        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->collector, $this->company, ['collect']);
        $this->today = service('timezone')->todayFor($this->company);
    }

    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime($this->today . " -{$days} days"));
    }

    // ------------------------------------------------------------------ derivation

    public function testDaysOverdueIsCountedFromTheDayAfterTheDueDate(): void
    {
        $inv = ['status' => 'issued', 'due_date' => '2026-10-10'];

        $this->assertSame(0, Collections::daysOverdue($inv, '2026-10-09'));
        $this->assertSame(0, Collections::daysOverdue($inv, '2026-10-10'), 'Due today is not late.');
        $this->assertSame(1, Collections::daysOverdue($inv, '2026-10-11'));
        $this->assertSame(21, Collections::daysOverdue($inv, '2026-10-31'));
        $this->assertSame(3, Collections::daysOverdue(['status' => 'part_paid'] + $inv, '2026-10-13'));
    }

    public function testOnlyInvoicesBeingCollectedCanBeOverdue(): void
    {
        foreach (['draft', 'in_approval', 'approved', 'paid', 'cancelled', 'rejected', 'returned'] as $status) {
            $this->assertFalse(Collections::isOverdue(['status' => $status, 'due_date' => '2020-01-01'], '2026-10-23'), $status);
        }
    }

    public function testOutstandingIsAmountLessPaid(): void
    {
        $this->assertSame('150.25', Collections::outstanding(['amount' => '250.00', 'amount_paid' => '99.75']));
        $this->assertSame('250.00', Collections::outstanding(['amount' => '250.00', 'amount_paid' => '0.00']));
    }

    public function testOverdueListIsMostOverdueFirstAndUsesTheCompanyCalendar(): void
    {
        $late  = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(10)]);
        $less  = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(2), 'status' => 'part_paid', 'amount_paid' => '10.00']);
        $today = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->today]);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(30), 'status' => 'paid', 'amount_paid' => '250.00']);

        $list = (new Collections($this->db))->overdue();

        $this->assertSame([$late, $less], array_map('intval', array_column($list, 'id')));
        $this->assertSame([10, 2], array_column($list, 'days_overdue'));
        $this->assertNotContains($today, array_column($list, 'id'));
    }

    // ------------------------------------------------------------------ reminders

    public function testRemindsTheRaiserAndCollectorsOnce(): void
    {
        $id = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(1)]);

        $stats = (new OverdueReminder($this->db))->run();

        $this->assertSame(['overdue' => 1, 'reminded' => 1, 'skipped' => 0, 'recipients' => 2], $stats);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.overdue'));
        $this->assertSame(1, $this->bell($this->collector, 'ar.overdue'));
        $reminder = $this->db->table('ar_reminders')->where('invoice_id', $id)->get()->getRowArray();
        $this->assertSame($this->today, $reminder['sent_on']);
        $this->assertSame(1, (int) $reminder['days_overdue']);
        $this->assertSame(2, (int) $reminder['recipients']);
    }

    public function testRunningAgainTheSameDayDoesNothing(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(3)]);
        $reminder = new OverdueReminder($this->db);

        $reminder->run();
        $second = $reminder->run();

        $this->assertSame(['overdue' => 1, 'reminded' => 0, 'skipped' => 1, 'recipients' => 0], $second);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.overdue'));
        $this->assertSame(1, $this->db->table('ar_reminders')->countAllResults());
    }

    public function testRemindsAgainAfterTheInterval(): void
    {
        $id       = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(20)]);
        $reminder = new OverdueReminder($this->db);
        $reminder->run();

        // Six days on: still too soon. Seven days on: due again.
        $this->db->table('ar_reminders')->where('invoice_id', $id)->update(['sent_on' => $this->ago(6)]);
        $this->assertSame(0, $reminder->run()['reminded']);

        $this->db->table('ar_reminders')->where('invoice_id', $id)->update(['sent_on' => $this->ago(7)]);
        $this->assertSame(1, $reminder->run()['reminded']);
        $this->assertSame(2, $this->bell($this->raiser, 'ar.overdue'));
        $this->assertSame(2, $this->db->table('ar_reminders')->where('invoice_id', $id)->countAllResults());
    }

    public function testTheIntervalIsASetting(): void
    {
        setting()->set('ReceivablesSettings.remindEveryDays', 2);
        $id       = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(20)]);
        $reminder = new OverdueReminder($this->db);
        $reminder->run();

        $this->db->table('ar_reminders')->where('invoice_id', $id)->update(['sent_on' => $this->ago(2)]);

        $this->assertSame(1, $reminder->run()['reminded']);
    }

    public function testADryRunSendsAndRecordsNothing(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(4)]);

        $stats = (new OverdueReminder($this->db))->run(true);

        $this->assertSame(['overdue' => 1, 'reminded' => 1, 'skipped' => 0, 'recipients' => 2], $stats);
        $this->assertSame(0, $this->bell($this->raiser, 'ar.overdue'));
        $this->assertSame(0, $this->db->table('ar_reminders')->countAllResults());
    }

    public function testNotYetDuePaidAndUnissuedInvoicesAreLeftAlone(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->today]);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(5), 'status' => 'paid', 'amount_paid' => '250.00']);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(5), 'status' => 'in_approval']);

        $this->assertSame(['overdue' => 0, 'reminded' => 0, 'skipped' => 0, 'recipients' => 0], (new OverdueReminder($this->db))->run());
    }

    public function testInactiveCollectorsAreNotTold(): void
    {
        $gone = $this->makeUserNamed('Gone Gary', null, false, false);
        $this->grant($gone, $this->company, ['collect']);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(1)]);

        $stats = (new OverdueReminder($this->db))->run();

        $this->assertSame(2, $stats['recipients']);
        $this->assertSame(0, $this->bell($gone, 'ar.overdue'));
    }

    public function testACollectorOfAnotherCompanyIsNotTold(): void
    {
        $other = $this->makeCompany('BETA');
        $elsewhere = $this->makeUserNamed('Ella Elsewhere');
        $this->grant($elsewhere, $other, ['collect']);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(1)]);

        (new OverdueReminder($this->db))->run();

        $this->assertSame(0, $this->bell($elsewhere, 'ar.overdue'));
    }

    public function testOnceItIsPaidItIsNoLongerRemindedAbout(): void
    {
        $id       = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(20)]);
        $reminder = new OverdueReminder($this->db);
        $reminder->run();
        $this->db->table('ar_reminders')->where('invoice_id', $id)->update(['sent_on' => $this->ago(30)]);

        (new \App\Core\Receivables\PaymentService($this->db))->record($id, $this->collector, ['amount' => '250.00', 'received_date' => $this->today]);

        $this->assertSame(0, $reminder->run()['overdue']);
    }

    // ------------------------------------------------------------------ the spark command

    public function testTheCommandRemindsAndCanBeRunRepeatedly(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(2)]);

        command('receivables:remind');
        $this->assertStringContainsString('1 overdue invoice(s): 1 reminded (2 people), 0 already reminded recently.', $this->getStreamFilterBuffer());
        $this->resetStreamFilterBuffer();

        command('receivables:remind');
        $this->assertStringContainsString('1 overdue invoice(s): 0 reminded (0 people), 1 already reminded recently.', $this->getStreamFilterBuffer());
        $this->assertSame(1, $this->bell($this->raiser, 'ar.overdue'));
    }

    public function testTheCommandDoesNothingWhileTheModuleIsOff(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(2)]);
        setting()->set('ModuleState.disabled', ['receivables']);
        Services::resetSingle('modules');

        command('receivables:remind');

        $this->assertStringContainsString('switched off', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->db->table('ar_reminders')->countAllResults());

        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
    }
}
