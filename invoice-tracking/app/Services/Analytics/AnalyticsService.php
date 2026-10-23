<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\AccessScope;
use App\Services\ReportService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Read-only queries behind the long-term analytics screens. Every query is restricted through AccessScope
 * (member companies, or everything for group-level users), exactly like the live reports. Money is always
 * returned per currency and never added across currencies.
 */
final class AnalyticsService
{
    /** key => label */
    public const GRANULARITIES = ['month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year'];

    /** Step grouping => column. */
    private const STEP_GROUPS = ['type' => 's.step_type', 'assignee' => 's.assignee_label', 'actor' => 's.acted_by_name'];

    public function __construct(private ?BaseConnection $analytics = null, private ?AccessScope $scope = null)
    {
        $this->analytics ??= db_connect(AnalyticsMigrator::GROUP);
        $this->scope     ??= new AccessScope(db_connect());
    }

    // ------------------------------------------------------------------ filters and periods

    /**
     * Validates the query string into a filter set. Unknown or malformed values fall back to defaults.
     *
     * @param array<string, mixed> $get
     *
     * @return array{company: int, group: int, type: string, supplier: string, from: string, to: string, gran: string}
     */
    public static function normalise(array $get, ?string $today = null, bool $allowDay = false): array
    {
        $today ??= date('Y-m-d');
        $valid   = static function (mixed $v): ?string {
            $d = is_string($v) ? date_create_from_format('Y-m-d', $v) : false;

            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };

        $to   = $valid($get['to'] ?? null);
        $to   = $to !== null && $to <= $today ? $to : $today;
        $from = $valid($get['from'] ?? null) ?? date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -23 months'));
        if ($from > $to) {
            $from = date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -23 months'));
        }

        $gran = (string) ($get['gran'] ?? 'month');
        if (! isset(self::GRANULARITIES[$gran]) && ! ($allowDay && $gran === 'day')) {
            $gran = 'month';
        }

        return [
            'company'  => max(0, (int) ($get['company'] ?? 0)),
            'group'    => max(0, (int) ($get['group'] ?? 0)),
            'type'     => mb_substr(trim((string) ($get['type'] ?? '')), 0, 200),
            'supplier' => mb_substr(trim((string) ($get['supplier'] ?? '')), 0, 200),
            'from'     => $from,
            'to'       => $to,
            'gran'     => $gran,
        ];
    }

    public static function bucketSql(string $column, string $gran): string
    {
        return match ($gran) {
            'year'    => "CAST(YEAR({$column}) AS CHAR)",
            'quarter' => "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))",
            'day'     => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default   => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /** Bucket key of a date, matching bucketSql. */
    public static function keyFor(string $date, string $gran): string
    {
        $t = strtotime($date);

        return match ($gran) {
            'year'    => date('Y', $t),
            'quarter' => date('Y', $t) . '-Q' . (int) ceil((int) date('n', $t) / 3),
            'day'     => date('Y-m-d', $t),
            default   => date('Y-m', $t),
        };
    }

    /**
     * Bucket keys from $from to $to, oldest first.
     *
     * @return list<string>
     */
    public static function periods(string $from, string $to, string $gran): array
    {
        $keys = [];
        $step = match ($gran) {
            'year' => '+1 year', 'quarter' => '+3 months', 'day' => '+1 day', default => '+1 month',
        };
        $d = $gran === 'day' ? $from : substr($from, 0, 7) . '-01';
        for ($i = 0; $i < 2000 && $d <= $to; $i++) {
            $keys[self::keyFor($d, $gran)] = true;
            $d = date('Y-m-d', strtotime($d . ' ' . $step));
        }

        return array_map('strval', array_keys($keys));
    }

    /** The same period one year earlier ('2026-03' => '2025-03', '2026-Q2' => '2025-Q2', '2026' => '2025'). */
    public static function priorYear(string $key): string
    {
        return preg_replace_callback('/^\d{4}/', static fn (array $m): string => (string) ((int) $m[0] - 1), $key) ?? $key;
    }

    // ------------------------------------------------------------------ volume and spend

    /**
     * Invoices submitted, processed and paid per period and currency. Rows also cover the year before the
     * range so each period can be compared with the same period a year earlier.
     *
     * @param array<string, mixed> $f
     *
     * @return array{periods: list<string>, currencies: list<string>, rows: array<string, array<string, array<string, array{0: int, 1: float}>>>}
     */
    public function volume(int $uid, array $f): array
    {
        $wide = ['from' => date('Y-m-d', strtotime($f['from'] . ' -1 year'))] + $f;
        $rows = [];
        $currencies = [];

        foreach (['submitted' => 'f.first_submitted_at', 'processed' => 'f.processed_at', 'paid' => 'f.paid_at'] as $metric => $col) {
            $b = $this->facts($uid, $wide, $col)
                ->select(self::bucketSql($col, $f['gran']) . ' AS period, f.currency, COUNT(*) AS n, COALESCE(SUM(f.amount), 0) AS amt', false)
                ->groupBy('period, f.currency');
            if ($metric === 'submitted') {
                $b->where('f.status !=', 'cancelled');
            }
            foreach ($b->get()->getResultArray() as $r) {
                $rows[(string) $r['period']][(string) $r['currency']][$metric] = [(int) $r['n'], round((float) $r['amt'], 2)];
                $currencies[(string) $r['currency']] = true;
            }
        }
        ksort($currencies);

        return ['periods' => self::periods($f['from'], $f['to'], $f['gran']), 'currencies' => array_keys($currencies), 'rows' => $rows];
    }

    /**
     * Largest suppliers by submitted amount in the range, per currency.
     *
     * @param array<string, mixed> $f
     *
     * @return array<string, list<array{supplier: string, code: string, count: int, amount: float}>> currency => suppliers
     */
    public function topSuppliers(int $uid, array $f, int $limit = 10): array
    {
        $col = 'f.first_submitted_at';
        $rows = $this->facts($uid, $f, $col)->where('f.status !=', 'cancelled')
            ->select("f.currency, COALESCE(f.supplier_name, '" . ReportService::NO_SUPPLIER . "') AS supplier, MAX(f.supplier_code) AS code, COUNT(*) AS n, COALESCE(SUM(f.amount), 0) AS amt", false)
            ->groupBy('f.currency, supplier')->orderBy('amt', 'DESC')->limit(500)->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            if (count($out[$cur] ?? []) < $limit) {
                $out[$cur][] = ['supplier' => (string) $r['supplier'], 'code' => (string) ($r['code'] ?? ''), 'count' => (int) $r['n'], 'amount' => round((float) $r['amt'], 2)];
            }
        }
        ksort($out);

        return $out;
    }

    // ------------------------------------------------------------------ cycle time

    /**
     * Per period: how long invoices take, how many go back, and step-level SLA performance.
     *
     * @param array<string, mixed> $f
     *
     * @return array{periods: list<string>, rows: array<string, array<string, float|int|null>>}
     */
    public function cycle(int $uid, array $f): array
    {
        $rows = [];
        $g = $f['gran'];

        $col = 'f.processed_at';
        $done = $this->facts($uid, $f, $col)
            ->select(self::bucketSql($col, $g) . ' AS period, COUNT(*) AS done, AVG(f.days_to_processed) AS avg_processed, AVG(f.days_to_paid) AS avg_paid, '
                . 'SUM(CASE WHEN f.on_time IS NOT NULL THEN 1 ELSE 0 END) AS with_due, SUM(CASE WHEN f.on_time = 1 THEN 1 ELSE 0 END) AS on_time', false)
            ->groupBy('period')->get()->getResultArray();
        foreach ($done as $r) {
            $rows[(string) $r['period']] = [
                'done' => (int) $r['done'], 'avg_processed' => $this->round($r['avg_processed']), 'avg_paid' => $this->round($r['avg_paid']),
                'on_time_pct' => (int) $r['with_due'] > 0 ? round((int) $r['on_time'] / (int) $r['with_due'] * 100, 1) : null,
            ];
        }

        $col = 'f.first_submitted_at';
        $sub = $this->facts($uid, $f, $col)->where('f.status !=', 'cancelled')
            ->select(self::bucketSql($col, $g) . ' AS period, COUNT(*) AS submitted, SUM(CASE WHEN f.return_count > 0 THEN 1 ELSE 0 END) AS returned, '
                . 'SUM(CASE WHEN f.resubmit_count > 0 THEN 1 ELSE 0 END) AS resubmitted, SUM(CASE WHEN f.override_count > 0 THEN 1 ELSE 0 END) AS overridden, '
                . "SUM(CASE WHEN f.status = 'rejected' THEN 1 ELSE 0 END) AS rejected", false)
            ->groupBy('period')->get()->getResultArray();
        foreach ($sub as $r) {
            $n = max(1, (int) $r['submitted']);
            $rows[(string) $r['period']] = ($rows[(string) $r['period']] ?? []) + [
                'submitted' => (int) $r['submitted'],
                'returned_pct' => round((int) $r['returned'] / $n * 100, 1), 'resubmitted_pct' => round((int) $r['resubmitted'] / $n * 100, 1),
                'overridden_pct' => round((int) $r['overridden'] / $n * 100, 1), 'rejected_pct' => round((int) $r['rejected'] / $n * 100, 1),
            ];
        }

        $col = 's.entered_at';
        $steps = $this->steps($uid, $f)
            ->select(self::bucketSql($col, $g) . ' AS period, COUNT(*) AS visits, SUM(CASE WHEN s.sla_days IS NOT NULL THEN 1 ELSE 0 END) AS with_sla, '
                . 'SUM(CASE WHEN s.sla_breached = 1 THEN 1 ELSE 0 END) AS breached', false)
            ->groupBy('period')->get()->getResultArray();
        foreach ($steps as $r) {
            $rows[(string) $r['period']] = ($rows[(string) $r['period']] ?? []) + [
                'sla_breach_pct' => (int) $r['with_sla'] > 0 ? round((int) $r['breached'] / (int) $r['with_sla'] * 100, 1) : null,
            ];
        }

        return ['periods' => self::periods($f['from'], $f['to'], $g), 'rows' => $rows];
    }

    /**
     * Where invoices spend their time.
     *
     * @param array<string, mixed> $f
     * @param string               $by type | assignee | actor
     *
     * @return list<array{label: string, visits: int, avg_hours: float|null, max_hours: float|null, breached: int, with_sla: int, sent_back: int, chased: int, overrides: int}>
     */
    public function stepStats(int $uid, array $f, string $by = 'type', int $limit = 15): array
    {
        $col = self::STEP_GROUPS[$by] ?? self::STEP_GROUPS['type'];
        $rows = $this->steps($uid, $f)->where("{$col} IS NOT NULL", null, false)
            ->select("{$col} AS label, COUNT(*) AS visits, AVG(s.duration_hours) AS avg_hours, MAX(s.duration_hours) AS max_hours, "
                . 'SUM(CASE WHEN s.sla_days IS NOT NULL THEN 1 ELSE 0 END) AS with_sla, SUM(CASE WHEN s.sla_breached = 1 THEN 1 ELSE 0 END) AS breached, '
                . "SUM(CASE WHEN s.outcome IN ('returned', 'returned_previous', 'rejected') THEN 1 ELSE 0 END) AS sent_back, "
                . 'SUM(CASE WHEN s.escalation_level IS NOT NULL THEN 1 ELSE 0 END) AS chased, SUM(s.override) AS overrides', false)
            ->groupBy('label')->orderBy('avg_hours', 'DESC')->limit($limit)->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'label' => (string) $r['label'], 'visits' => (int) $r['visits'],
            'avg_hours' => $r['avg_hours'] !== null ? round((float) $r['avg_hours'], 1) : null, 'max_hours' => $r['max_hours'] !== null ? round((float) $r['max_hours'], 1) : null,
            'breached' => (int) $r['breached'], 'with_sla' => (int) $r['with_sla'], 'sent_back' => (int) $r['sent_back'], 'chased' => (int) $r['chased'], 'overrides' => (int) $r['overrides'],
        ], $rows);
    }

    // ------------------------------------------------------------------ backlog history

    /**
     * The outstanding backlog over time. One point per period (the last snapshot in it), or per day for gran=day.
     *
     * @param array<string, mixed> $f
     *
     * @return array{points: list<array{date: string, estimated: bool, by: array<string, array<string, array{0: int, 1: float}>>}>, currencies: list<string>}
     */
    public function backlog(int $uid, array $f): array
    {
        $b = $this->analytics->table('snap_backlog_daily d')->join('dim_company c', 'c.company_id = d.company_id')
            ->select('d.snapshot_date, d.currency, d.age_band, SUM(d.invoice_count) AS n, SUM(d.amount) AS amt, MAX(d.reconstructed) AS est', false)
            ->where('d.snapshot_date >=', $f['from'])->where('d.snapshot_date <=', $f['to'])
            ->groupBy('d.snapshot_date, d.currency, d.age_band')->orderBy('d.snapshot_date');
        $this->scope->restrict($b, $uid, 'd.company_id');
        $this->narrow($b, $f, 'd.company_id');

        $byDate = [];
        $currencies = [];
        foreach ($b->get()->getResultArray() as $r) {
            $d = (string) $r['snapshot_date'];
            $byDate[$d]['by'][(string) $r['currency']][(string) $r['age_band']] = [(int) $r['n'], round((float) $r['amt'], 2)];
            $byDate[$d]['est'] = ($byDate[$d]['est'] ?? false) || (int) $r['est'] === 1;
            $currencies[(string) $r['currency']] = true;
        }

        // Keep the last snapshot of each period.
        $last = [];
        foreach (array_keys($byDate) as $d) {
            $last[self::keyFor($d, $f['gran'])] = $d;
        }
        $points = [];
        foreach ($last as $d) {
            $points[] = ['date' => $d, 'estimated' => $byDate[$d]['est'], 'by' => $byDate[$d]['by']];
        }
        ksort($currencies);

        return ['points' => $points, 'currencies' => array_keys($currencies)];
    }

    // ------------------------------------------------------------------ page support

    /**
     * @return array{last_run: string|null, has_data: bool, since: string|null}
     */
    public function status(): array
    {
        $run = $this->analytics->table('etl_state')->selectMax('last_run', 'm')->get()->getRowArray();
        $inv = $this->analytics->table('fact_invoice')->select('COUNT(*) AS n, MIN(first_submitted_at) AS since', false)->get()->getRowArray();

        return ['last_run' => ($run['m'] ?? null) ?: null, 'has_data' => (int) ($inv['n'] ?? 0) > 0, 'since' => ($inv['since'] ?? null) ? substr((string) $inv['since'], 0, 10) : null];
    }

    /**
     * Invoice type names the user may filter by.
     *
     * @return list<string>
     */
    public function typeNames(int $uid): array
    {
        $b = $this->analytics->table('dim_invoice_type t')->select('t.name')->distinct()->orderBy('t.name');
        $this->scope->restrict($b, $uid, 't.company_id');

        return array_map(static fn (array $r): string => (string) $r['name'], $b->get()->getResultArray());
    }

    // ------------------------------------------------------------------ query builders

    /** invoice facts restricted to the user, filtered, with the date column limited to the range */
    private function facts(int $uid, array $f, string $dateColumn): BaseBuilder
    {
        $b = $this->analytics->table('fact_invoice f')
            ->join('dim_company c', 'c.company_id = f.company_id')
            ->join('dim_invoice_type t', 't.invoice_type_id = f.invoice_type_id', 'left')
            ->where("{$dateColumn} >=", $f['from'] . ' 00:00:00')->where("{$dateColumn} <=", $f['to'] . ' 23:59:59');
        $this->scope->restrict($b, $uid, 'f.company_id');

        return $this->narrow($b, $f, 'f.company_id', true);
    }

    /** step visits (with their invoice for type/supplier filters), restricted and filtered, entered within the range */
    private function steps(int $uid, array $f): BaseBuilder
    {
        $b = $this->analytics->table('fact_step s')
            ->join('fact_invoice f', 'f.invoice_id = s.invoice_id')
            ->join('dim_company c', 'c.company_id = s.company_id')
            ->join('dim_invoice_type t', 't.invoice_type_id = f.invoice_type_id', 'left')
            ->where('s.entered_at >=', $f['from'] . ' 00:00:00')->where('s.entered_at <=', $f['to'] . ' 23:59:59');
        $this->scope->restrict($b, $uid, 's.company_id');

        return $this->narrow($b, $f, 's.company_id', true);
    }

    /** Narrowing filters only ever reduce what AccessScope already allows. */
    private function narrow(BaseBuilder $b, array $f, string $companyColumn, bool $invoiceLevel = false): BaseBuilder
    {
        if (($f['company'] ?? 0) > 0) {
            $b->where($companyColumn, (int) $f['company']);
        }
        if (($f['group'] ?? 0) > 0) {
            $b->like('c.group_path', ',' . (int) $f['group'] . ',');
        }
        if ($invoiceLevel) {
            if (($f['type'] ?? '') !== '') {
                $b->where('t.name', $f['type']);
            }
            if (($f['supplier'] ?? '') !== '') {
                $b->groupStart()->where('f.supplier_code', $f['supplier'])->orWhere('f.supplier_name', $f['supplier'])->groupEnd();
            }
        }

        return $b;
    }

    private function round(mixed $v): ?float
    {
        return $v !== null ? round((float) $v, 1) : null;
    }
}
