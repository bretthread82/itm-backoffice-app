<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\ReceivablesException;
use App\Core\Receivables\SubscriptionBilling;
use App\Core\Receivables\SubscriptionService;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * Subscriptions raise one draft invoice per cycle however often the command runs, advance on the same day of the month,
 * and tell the owner and collectors once per lead-time window.
 *
 * @internal
 */
final class SubscriptionBillingTest extends ReceivablesTestCase
{
    use StreamFilterTrait;

    private int $company;
    private int $owner;
    private int $collector;
    private int $type;
    private string $today;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        setting()->set('ReceivablesSettings.subscriptionLeadDays', '14,7,1');
        $this->company   = $this->makeCompany('ACME');
        $this->owner     = $this->makeUserNamed('Olive Owner');
        $this->collector = $this->makeUserNamed('Cal Collector');
        $this->grant($this->owner, $this->company, ['submit']);
        $this->grant($this->collector, $this->company, ['collect']);
        $this->type    = $this->makeArRoute($this->company, [['type' => 'issue', 'user' => $this->owner]])['type'];
        $this->today   = service('timezone')->todayFor($this->company);
        $this->service = new SubscriptionService($this->db);
    }

    private function inDays(int $days): string
    {
        return date('Y-m-d', strtotime($this->today . ' ' . ($days >= 0 ? '+' : '-') . abs($days) . ' days'));
    }

    /**
     * @param array<string, mixed> $over
     */
    private function subscribe(array $over = []): int
    {
        return $this->service->create($this->owner, $over + [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'customer' => 'Harbour Foods', 'description' => 'Managed hosting',
            'amount' => '1,200.00', 'currency' => 'USD', 'frequency' => 'monthly', 'next_bill_date' => $this->today, 'payment_terms_days' => 30,
        ]);
    }

    /** @return array<string, mixed> */
    private function sub(int $id): array
    {
        return $this->db->table('ar_subscriptions')->where('id', $id)->get()->getRowArray();
    }

    // ------------------------------------------------------------------ dates

    public function testBillDatesKeepTheDayOfTheMonthAndClampInShortMonths(): void
    {
        $this->assertSame('2026-02-28', SubscriptionService::nextDate('2026-01-31', 'monthly', 31));
        $this->assertSame('2026-03-31', SubscriptionService::nextDate('2026-02-28', 'monthly', 31));
        $this->assertSame('2028-02-29', SubscriptionService::nextDate('2028-01-31', 'monthly', 31), 'Leap year.');
        $this->assertSame('2026-04-15', SubscriptionService::nextDate('2026-01-15', 'quarterly', 15));
        $this->assertSame('2027-02-28', SubscriptionService::nextDate('2026-02-28', 'yearly', 28));
        $this->assertSame('2027-01-15', SubscriptionService::nextDate('2026-12-15', 'monthly', 15), 'Year end.');
    }

    // ------------------------------------------------------------------ setting up

    public function testSettingUpNeedsTheRaiseRightForTheCompany(): void
    {
        $this->expectException(ReceivablesException::class);

        $this->service->create($this->collector, [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'customer' => 'X', 'description' => 'Y', 'amount' => '1',
            'currency' => 'USD', 'frequency' => 'monthly', 'next_bill_date' => $this->today, 'payment_terms_days' => 30,
        ]);
    }

    public function testBadDetailsAreRefusedWithEveryReason(): void
    {
        try {
            $this->subscribe(['customer' => '', 'description' => '', 'amount' => '0', 'frequency' => 'weekly', 'next_bill_date' => 'soon']);
            $this->fail('Expected a ReceivablesException.');
        } catch (ReceivablesException $e) {
            foreach (['Customer is required.', 'Say what the customer subscribes to.', 'Amount must be more than zero.', 'Choose how often it is billed.', 'next bill date is required'] as $needle) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $this->assertSame(0, $this->db->table('ar_subscriptions')->countAllResults());
    }

    public function testATypeFromAnotherCompanyIsRefused(): void
    {
        $other = $this->makeCompany('OTHER');
        $this->grant($this->owner, $other, ['submit']);

        $this->expectException(ReceivablesException::class);
        $this->subscribe(['company_id' => $other]);
    }

    // ------------------------------------------------------------------ billing

    public function testADueSubscriptionRaisesOneDraftAndMovesOn(): void
    {
        $id = $this->subscribe();

        $stats = (new SubscriptionBilling($this->db))->bill();

        $this->assertSame(1, $stats['billed']);
        $link = $this->db->table('ar_subscription_invoices')->where('subscription_id', $id)->get()->getRowArray();
        $inv  = $this->arRow((int) $link['invoice_id']);

        $this->assertSame('draft', $inv['status']);
        $this->assertSame($this->owner, (int) $inv['submitted_by']);
        $this->assertSame('Harbour Foods', $inv['customer']);
        $this->assertSame('1200.00', $inv['amount']);
        $this->assertSame($this->today, $inv['invoice_date']);
        $this->assertSame($this->inDays(30), $inv['due_date']);
        $this->assertNull($inv['invoice_number'], 'The number is added by a person before submitting.');
        $this->assertSame(SubscriptionService::nextDate($this->today, 'monthly', (int) date('j', strtotime($this->today))), $this->sub($id)['next_bill_date']);
    }

    public function testRunningAgainDoesNotBillTheSameCycleTwice(): void
    {
        $this->subscribe();
        $billing = new SubscriptionBilling($this->db);

        $billing->bill();
        $second = $billing->bill();

        $this->assertSame(0, $second['billed']);
        $this->assertSame(1, $this->db->table('ar_invoices')->countAllResults());
        $this->assertSame(1, $this->db->table('ar_subscription_invoices')->countAllResults());
    }

    public function testAFutureSubscriptionIsNotBilledYet(): void
    {
        $this->subscribe(['next_bill_date' => $this->inDays(3)]);

        $this->assertSame(0, (new SubscriptionBilling($this->db))->bill()['billed']);
        $this->assertSame(0, $this->db->table('ar_invoices')->countAllResults());
    }

    public function testMissedCyclesAreCaughtUpAFewAtATime(): void
    {
        $id = $this->subscribe(['next_bill_date' => $this->inDays(-70)]);

        $stats = (new SubscriptionBilling($this->db))->bill();

        $this->assertSame(3, $stats['billed'], 'At most three cycles per run.');
        $this->assertSame(3, $this->db->table('ar_subscription_invoices')->where('subscription_id', $id)->countAllResults());
    }

    public function testAPausedSubscriptionIsSkippedAndResumingSkipsThePausedCycles(): void
    {
        $id = $this->subscribe(['next_bill_date' => $this->inDays(-70)]);
        $this->service->setStatus($id, $this->owner, 'pause');

        $this->assertSame(0, (new SubscriptionBilling($this->db))->bill()['billed']);

        $this->service->setStatus($id, $this->owner, 'resume');
        $next = $this->sub($id)['next_bill_date'];

        $this->assertGreaterThanOrEqual($this->today, $next);
        $this->assertSame('active', $this->sub($id)['status']);
        $this->assertSame(0, $this->db->table('ar_invoices')->countAllResults());
    }

    public function testTheLastCycleBeforeTheEndDateEndsTheSubscription(): void
    {
        $id = $this->subscribe(['end_date' => $this->inDays(10)]);

        $stats = (new SubscriptionBilling($this->db))->bill();

        $this->assertSame(1, $stats['billed']);
        $this->assertSame('ended', $this->sub($id)['status']);
    }

    public function testACycleThatCannotBeBilledLeavesNothingBehindAndTellsTheOwner(): void
    {
        $id = $this->subscribe();
        $this->db->table('user_company_capabilities')->where(['user_id' => $this->owner, 'capability' => 'receivables.submit'])->delete();

        $stats = (new SubscriptionBilling($this->db))->bill();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $this->db->table('ar_invoices')->countAllResults());
        $this->assertSame(0, $this->db->table('ar_subscription_invoices')->countAllResults(), 'The claim is rolled back so the next run retries.');
        $this->assertSame($this->today, $this->sub($id)['next_bill_date']);
        $this->assertSame(1, $this->bell($this->owner, 'ar.subscription_failed'));
    }

    public function testBillingTellsTheOwnerAndTheCollectors(): void
    {
        $this->subscribe();

        (new SubscriptionBilling($this->db))->bill();

        $this->assertSame(1, $this->bell($this->owner, 'ar.subscription_billed'));
        $this->assertSame(1, $this->bell($this->collector, 'ar.subscription_billed'));
    }

    public function testDryRunChangesNothing(): void
    {
        $id = $this->subscribe();

        $stats = (new SubscriptionBilling($this->db))->bill(true);

        $this->assertSame(1, $stats['billed']);
        $this->assertSame(0, $this->db->table('ar_invoices')->countAllResults());
        $this->assertSame($this->today, $this->sub($id)['next_bill_date']);
    }

    // ------------------------------------------------------------------ reminders

    public function testPeopleAreToldOncePerWindow(): void
    {
        $this->subscribe(['next_bill_date' => $this->inDays(7)]);
        $billing = new SubscriptionBilling($this->db);

        $first  = $billing->remind();
        $second = $billing->remind();

        $this->assertSame(1, $first['notified']);
        $this->assertSame(2, $first['recipients'], 'The owner and the collector.');
        $this->assertSame(0, $second['notified']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, $this->bell($this->owner, 'ar.subscription_due'));
        $this->assertSame(1, $this->bell($this->collector, 'ar.subscription_due'));
    }

    public function testEachWindowNotifiesOnceAsTheDateApproaches(): void
    {
        $id = $this->subscribe(['next_bill_date' => $this->inDays(14)]);
        $billing = new SubscriptionBilling($this->db);
        $billing->remind();

        foreach ([7, 1] as $left) {
            $this->db->table('ar_subscriptions')->where('id', $id)->update(['next_bill_date' => $this->inDays($left)]);
            $this->assertSame(1, $billing->remind()['notified'], "{$left} days left");
        }

        $this->assertSame(3, $this->bell($this->owner, 'ar.subscription_due'));
        $this->assertSame([1, 7, 14], array_map('intval', array_column($this->db->table('ar_subscription_notices')->orderBy('lead_days')->get()->getResultArray(), 'lead_days')));
    }

    public function testNothingIsSentOutsideTheWindowsOrForPausedSubscriptions(): void
    {
        $far    = $this->subscribe(['next_bill_date' => $this->inDays(40)]);
        $paused = $this->subscribe(['next_bill_date' => $this->inDays(5)]);
        $this->service->setStatus($paused, $this->owner, 'pause');

        $stats = (new SubscriptionBilling($this->db))->remind();

        $this->assertSame(0, $stats['upcoming']);
        $this->assertSame(0, $this->db->table('notifications')->like('type', 'ar.', 'after')->countAllResults());
        $this->assertNotSame($far, $paused);
    }

    public function testOnlyActiveStaffAreTold(): void
    {
        $this->subscribe(['next_bill_date' => $this->inDays(1)]);
        $this->setActive($this->collector, false);

        (new SubscriptionBilling($this->db))->remind();

        $this->assertSame(1, $this->bell($this->owner, 'ar.subscription_due'));
        $this->assertSame(0, $this->bell($this->collector, 'ar.subscription_due'));
    }

    // ------------------------------------------------------------------ the spark command

    public function testTheCommandBillsAndRemindsAndCanBeRunRepeatedly(): void
    {
        $this->subscribe();
        $this->subscribe(['next_bill_date' => $this->inDays(7), 'customer' => 'Other Co']);

        command('receivables:subscriptions');
        $this->assertStringContainsString('1 subscription cycle(s) due: 1 billed, 0 failed, 0 ended. 1 coming up: 1 notified (2 people), 0 already notified.', $this->getStreamFilterBuffer());
        $this->resetStreamFilterBuffer();

        command('receivables:subscriptions');
        $this->assertStringContainsString('0 subscription cycle(s) due: 0 billed', $this->getStreamFilterBuffer());
        $this->assertSame(1, $this->db->table('ar_invoices')->countAllResults());
    }

    public function testTheCommandDoesNothingWhileTheModuleIsOff(): void
    {
        $this->subscribe();
        setting()->set('ModuleState.disabled', ['receivables']);
        Services::resetSingle('modules');

        command('receivables:subscriptions');

        $this->assertStringContainsString('switched off', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->db->table('ar_invoices')->countAllResults());

        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
    }
}
