<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\AnalyticsMigrator;
use App\Services\Analytics\BacklogSnapshotter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Analytics database only (invoicing_test_analytics).
 *
 * @internal
 */
final class BacklogSnapshotterTest extends CIUnitTestCase
{
    private BaseConnection $analytics;
    private BacklogSnapshotter $snap;

    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsMigrator::wipe();
        (new AnalyticsMigrator())->upgrade();
        $this->analytics = db_connect(AnalyticsMigrator::GROUP);
        $this->snap      = new BacklogSnapshotter($this->analytics);
    }

    private function fact(int $id, array $over = []): void
    {
        $this->analytics->table('fact_invoice')->insert($over + [
            'invoice_id' => $id, 'company_id' => 1, 'currency' => 'USD', 'amount' => '100.00', 'status' => 'in_approval', 'outstanding' => 1,
            'due_date' => '2026-10-01', 'first_submitted_at' => '2026-09-01 09:00:00', 'ended_at' => null, 'loaded_at' => '2026-10-20 01:30:00',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $where = '1 = 1'): array
    {
        return $this->analytics->table('snap_backlog_daily')->where($where, null, false)->orderBy('snapshot_date, company_id, currency, age_band')->get()->getResultArray();
    }

    public function testSnapshotGroupsByCompanyCurrencyAndBandAndIsRepeatable(): void
    {
        $this->fact(1);                                                                   // 19 days past due
        $this->fact(2, ['amount' => '50.00', 'due_date' => '2026-11-01']);                // not yet due
        $this->fact(3, ['currency' => 'NZD', 'amount' => '20.00', 'due_date' => '2026-07-01']); // 111 days
        $this->fact(4, ['outstanding' => 0, 'status' => 'paid']);                         // finished: excluded

        $this->assertSame(3, $this->snap->snapshot('2026-10-20'));
        $this->assertSame(3, $this->snap->snapshot('2026-10-20'), 'a re-run replaces the day, never doubles it');

        $got = array_map(static fn (array $r): string => "{$r['currency']}:{$r['age_band']}:{$r['invoice_count']}:" . (float) $r['amount'] . ":{$r['reconstructed']}", $this->rows());
        $this->assertSame(['NZD:90+:1:20:0', 'USD:1-30:1:100:0', 'USD:current:1:50:0'], $got);
    }

    public function testReconstructEstimatesPastDaysWithoutTouchingRealSnapshots(): void
    {
        $this->fact(1, ['due_date' => '2026-10-11', 'first_submitted_at' => '2026-10-10 09:00:00', 'ended_at' => '2026-10-13 10:00:00', 'status' => 'paid', 'outstanding' => 0]);
        $this->fact(2, ['first_submitted_at' => null, 'status' => 'draft', 'outstanding' => 0]); // never submitted
        $this->analytics->table('snap_backlog_daily')->insert([
            'snapshot_date' => '2026-10-11', 'company_id' => 1, 'currency' => 'USD', 'age_band' => 'current', 'invoice_count' => 7, 'amount' => 700, 'reconstructed' => 0,
        ]);

        $this->assertSame(2, $this->snap->reconstruct(null, '2026-10-15'));

        $est = $this->rows('reconstructed = 1');
        $this->assertSame(['2026-10-10', '2026-10-12'], array_column($est, 'snapshot_date'), 'outstanding 10-10 to 10-12; 10-11 already has a real snapshot');
        $this->assertSame(['current', '1-30'], array_column($est, 'age_band'));
        $real = $this->rows('reconstructed = 0');
        $this->assertCount(1, $real);
        $this->assertSame(7, $real[0]['invoice_count'], 'real snapshot untouched');

        $this->assertSame(2, $this->snap->reconstruct(null, '2026-10-15'), 'idempotent');
        $this->assertCount(2, $this->rows('reconstructed = 1'));
    }

    public function testStillOutstandingInvoiceRunsToTheEndOfTheRange(): void
    {
        $this->fact(1, ['first_submitted_at' => '2026-10-13 09:00:00', 'due_date' => '2026-10-14']);

        $this->assertSame(3, $this->snap->reconstruct(null, '2026-10-15'));
        $this->assertSame(['current', 'current', '1-30'], array_column($this->rows(), 'age_band'));
    }
}
