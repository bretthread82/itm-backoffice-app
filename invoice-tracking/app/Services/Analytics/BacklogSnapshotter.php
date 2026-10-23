<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\ReportService;
use CodeIgniter\Database\BaseConnection;

/**
 * Daily picture of the outstanding backlog by ageing band, per company and currency (never summed across
 * currencies). Works from fact_invoice only, so run it after a load. Real snapshots are taken going forward;
 * earlier days are reconstructed from submit and end dates and flagged `reconstructed`, because edits to
 * due dates or amounts made in the past cannot be replayed.
 */
final class BacklogSnapshotter
{
    public function __construct(private ?BaseConnection $analytics = null)
    {
        $this->analytics ??= db_connect(AnalyticsMigrator::GROUP);
    }

    /**
     * Records the backlog as it stands in fact_invoice for $date (default today), replacing any rows for that day.
     *
     * @return int rows written
     */
    public function snapshot(?string $date = null): int
    {
        $date ??= date('Y-m-d');
        $rows = $this->analytics->table('fact_invoice')->select('company_id, currency, amount, due_date')->where('outstanding', 1)->get()->getResultArray();

        $buckets = [];
        foreach ($rows as $r) {
            $band = ReportService::bandFor(ReportService::daysPastDue($r['due_date'], $date));
            $this->add($buckets, $date, (int) $r['company_id'], (string) $r['currency'], $band, $r['amount']);
        }

        $this->analytics->table('snap_backlog_daily')->where(['snapshot_date' => $date])->delete();

        return $this->write($buckets, false);
    }

    /**
     * Rebuilds estimated history for days that have no real snapshot. An invoice counts as outstanding from the
     * day it was first submitted until the day it ended (paid, processed with no pay step, rejected or cancelled).
     *
     * @return int rows written
     */
    public function reconstruct(?string $from = null, ?string $to = null): int
    {
        $to ??= date('Y-m-d', strtotime('yesterday'));
        $rows = $this->analytics->table('fact_invoice')
            ->select('company_id, currency, amount, due_date, first_submitted_at, ended_at')
            ->where('first_submitted_at IS NOT NULL', null, false)->get()->getResultArray();
        if ($rows === []) {
            return 0;
        }

        $real = array_column($this->analytics->table('snap_backlog_daily')->select('snapshot_date')->distinct()->where('reconstructed', 0)->get()->getResultArray(), 'snapshot_date');
        $real = array_flip(array_map('strval', $real));

        $from ??= substr((string) min(array_column($rows, 'first_submitted_at')), 0, 10);
        $buckets = [];
        foreach ($rows as $r) {
            $start = max($from, substr((string) $r['first_submitted_at'], 0, 10));
            $end   = $r['ended_at'] !== null ? date('Y-m-d', strtotime(substr((string) $r['ended_at'], 0, 10) . ' -1 day')) : $to; // last day it was still outstanding
            $end   = min($end, $to);
            for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (isset($real[$d])) {
                    continue;
                }
                $band = ReportService::bandFor(ReportService::daysPastDue($r['due_date'], $d));
                $this->add($buckets, $d, (int) $r['company_id'], (string) $r['currency'], $band, $r['amount']);
            }
        }

        // Only estimated rows are replaced; real snapshots are never touched.
        $this->analytics->table('snap_backlog_daily')->where('reconstructed', 1)->where('snapshot_date >=', $from)->where('snapshot_date <=', $to)->delete();

        return $this->write($buckets, true);
    }

    /** @param array<string, array{date: string, company: int, currency: string, band: string, count: int, amount: float}> $buckets */
    private function add(array &$buckets, string $date, int $company, string $currency, string $band, mixed $amount): void
    {
        $key = "{$date}|{$company}|{$currency}|{$band}";
        $buckets[$key] ??= ['date' => $date, 'company' => $company, 'currency' => $currency, 'band' => $band, 'count' => 0, 'amount' => 0.0];
        $buckets[$key]['count']++;
        $buckets[$key]['amount'] += (float) ($amount ?? 0);
    }

    /** @param array<string, array{date: string, company: int, currency: string, band: string, count: int, amount: float}> $buckets */
    private function write(array $buckets, bool $reconstructed): int
    {
        $out = [];
        foreach ($buckets as $b) {
            $out[] = [
                'snapshot_date' => $b['date'], 'company_id' => $b['company'], 'currency' => $b['currency'], 'age_band' => $b['band'],
                'invoice_count' => $b['count'], 'amount' => round($b['amount'], 2), 'reconstructed' => $reconstructed ? 1 : 0,
            ];
        }
        foreach (array_chunk($out, 500) as $chunk) {
            $this->analytics->table('snap_backlog_daily')->insertBatch($chunk);
        }

        return count($out);
    }
}
