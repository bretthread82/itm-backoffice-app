<?php
/**
 * @var array{periods: list<string>, rows: array<string, array<string, float|int|null>>} $c
 * @var list<array<string, mixed>>                                                       $steps     step statistics for the chosen grouping
 * @var string                                                                           $by        type | assignee | actor
 * @var array<string, string>                                                            $stepViews
 * @var array<string, mixed>                                                             $f
 */

use App\Services\Analytics\AnalyticsService;

$ok    = ! $error && $status['has_data'];
$chart = static fn (array $spec): string => esc(json_encode($spec, JSON_UNESCAPED_UNICODE), 'attr');
$col   = static fn (string $key): array => array_map(static fn (string $p) => $c['rows'][$p][$key] ?? null, $c['periods']);
$pct   = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
$num   = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
$hours = static function ($h): string {
    if ($h === null) {
        return '—';
    }

    return $h >= 48 ? rtrim(rtrim(number_format($h / 24, 1), '0'), '.') . ' days' : rtrim(rtrim(number_format((float) $h, 1), '0'), '.') . ' h';
};
$stepQuery = static fn (string $k): string => site_url('admin/analytics/cycle-time') . '?' . http_build_query(array_filter($f, static fn ($v) => $v !== '' && $v !== 0) + ['by' => $k]);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData(['tab' => 'cycle', 'csvPath' => 'admin/analytics/cycle-time.csv'])->include('analytics/_top') ?>

<?php if ($ok): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Average days from submission</div>
                <div class="card-body">
                    <div class="chart-box"><canvas role="img" aria-label="Average days from submission to processed and to paid, per <?= esc($f['gran'], 'attr') ?>" data-chart="<?= $chart([
                        'labels' => $c['periods'], 'unit' => 'days',
                        'datasets' => [
                            ['label' => 'To processed', 'data' => $col('avg_processed'), 'color' => '#2583e8', 'type' => 'line'],
                            ['label' => 'To paid', 'data' => $col('avg_paid'), 'color' => '#20a66a', 'type' => 'line'],
                        ],
                    ]) ?>"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Rework and service levels</div>
                <div class="card-body">
                    <div class="chart-box"><canvas role="img" aria-label="Percentage of invoices on time, returned, overridden and steps over their service level, per <?= esc($f['gran'], 'attr') ?>" data-chart="<?= $chart([
                        'labels' => $c['periods'], 'unit' => '%',
                        'datasets' => [
                            ['label' => 'Processed on time', 'data' => $col('on_time_pct'), 'color' => '#20a66a', 'type' => 'line'],
                            ['label' => 'Returned for changes', 'data' => $col('returned_pct'), 'color' => '#f0a51b', 'type' => 'line'],
                            ['label' => 'Group-level override', 'data' => $col('overridden_pct'), 'color' => '#7b61c9', 'type' => 'line', 'dash' => true],
                            ['label' => 'Steps over service level', 'data' => $col('sla_breach_pct'), 'color' => '#e34850', 'type' => 'line'],
                        ],
                    ]) ?>"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">By period</div>
        <div class="table-responsive">
            <table class="table mg-table table-cards mb-0">
                <thead><tr><th>Period</th><th class="text-end">Submitted</th><th class="text-end">Processed</th><th class="text-end">Avg days to processed</th><th class="text-end">Avg days to paid</th><th class="text-end">On time</th><th class="text-end">Returned</th><th class="text-end">Resubmitted</th><th class="text-end">Rejected</th><th class="text-end">Override</th><th class="text-end">Steps over SLA</th></tr></thead>
                <tbody>
                <?php $any = false; foreach (array_reverse($c['periods']) as $p): $r = $c['rows'][$p] ?? null; if ($r === null) { continue; } $any = true; ?>
                    <tr>
                        <td data-label="Period"><?= esc($p) ?></td>
                        <td data-label="Submitted" class="text-end"><?= (int) ($r['submitted'] ?? 0) ?></td>
                        <td data-label="Processed" class="text-end"><?= (int) ($r['done'] ?? 0) ?></td>
                        <td data-label="Avg days to processed" class="text-end"><?= $num($r['avg_processed'] ?? null) ?></td>
                        <td data-label="Avg days to paid" class="text-end"><?= $num($r['avg_paid'] ?? null) ?></td>
                        <td data-label="On time" class="text-end"><?= $pct($r['on_time_pct'] ?? null) ?></td>
                        <td data-label="Returned" class="text-end"><?= $pct($r['returned_pct'] ?? null) ?></td>
                        <td data-label="Resubmitted" class="text-end"><?= $pct($r['resubmitted_pct'] ?? null) ?></td>
                        <td data-label="Rejected" class="text-end"><?= $pct($r['rejected_pct'] ?? null) ?></td>
                        <td data-label="Override" class="text-end"><?= $pct($r['overridden_pct'] ?? null) ?></td>
                        <td data-label="Steps over SLA" class="text-end"><?= $pct($r['sla_breach_pct'] ?? null) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (! $any): ?><tr><td colspan="11" class="text-body-secondary">No invoices in this range.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <span class="me-auto">Where invoices spend their time</span>
            <ul class="nav nav-pills small">
                <?php foreach ($stepViews as $k => $label): ?><li class="nav-item"><a class="nav-link py-1 <?= $k === $by ? 'active' : '' ?>" <?= $k === $by ? 'aria-current="page"' : '' ?> href="<?= esc($stepQuery($k), 'attr') ?>"><?= esc($label) ?></a></li><?php endforeach; ?>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table mg-table table-cards mb-0">
                <thead><tr><th><?= esc($stepViews[$by]) ?></th><th class="text-end">Visits</th><th class="text-end">Average time</th><th class="text-end">Longest</th><th class="text-end">Over SLA</th><th class="text-end">Sent back</th><th class="text-end">Chased</th><th class="text-end">Overrides</th></tr></thead>
                <tbody>
                <?php foreach ($steps as $s): ?>
                    <tr>
                        <td data-label="<?= esc($stepViews[$by], 'attr') ?>"><?= esc($by === 'type' ? ucfirst($s['label']) : $s['label']) ?></td>
                        <td data-label="Visits" class="text-end"><?= (int) $s['visits'] ?></td>
                        <td data-label="Average time" class="text-end"><?= $hours($s['avg_hours']) ?></td>
                        <td data-label="Longest" class="text-end"><?= $hours($s['max_hours']) ?></td>
                        <td data-label="Over SLA" class="text-end"><?= $s['with_sla'] > 0 ? (int) $s['breached'] . ' of ' . (int) $s['with_sla'] : '—' ?></td>
                        <td data-label="Sent back" class="text-end"><?= (int) $s['sent_back'] ?></td>
                        <td data-label="Chased" class="text-end"><?= (int) $s['chased'] ?></td>
                        <td data-label="Overrides" class="text-end"><?= (int) $s['overrides'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($steps === []): ?><tr><td colspan="8" class="text-body-secondary">No approval steps in this range.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-body-secondary">
            Average and longest time cover finished steps only. “Over SLA” compares a step with the service level set on its route (open steps count once they pass it).
            “Chased” means a reminder or escalation was sent. Earlier submissions of a resubmitted invoice use its latest route for step details.
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= base_url('assets/js/analytics-charts.js') ?>"></script>
<?= $this->endSection() ?>
