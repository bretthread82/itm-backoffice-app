<?php
/**
 * @var array{periods: list<string>, currencies: list<string>, rows: array<string, mixed>} $v
 * @var array<string, mixed>                                                               $counts chart series (counts)
 * @var array<string, array<string, list<mixed>>>                                          $money  currency => chart series (amounts)
 * @var array<string, list<array<string, mixed>>>                                          $top    currency => top suppliers
 * @var array<string, mixed>                                                               $f
 */

use App\Services\Analytics\AnalyticsService;

$ok     = ! $error && $status['has_data'];
$amount = static fn (float $a): string => number_format($a, 2);
$chart  = static fn (array $spec): string => esc(json_encode($spec, JSON_UNESCAPED_UNICODE), 'attr');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData(['tab' => 'volume', 'csvPath' => 'admin/analytics/volume.csv'])->include('analytics/_top') ?>

<?php if ($ok): ?>
    <div class="card mb-3">
        <div class="card-header">Invoices per <?= esc(strtolower(AnalyticsService::GRANULARITIES[$f['gran']])) ?></div>
        <div class="card-body">
            <div class="chart-box"><canvas role="img" aria-label="Invoices submitted, processed and paid per <?= esc($f['gran'], 'attr') ?>, with submissions a year earlier" data-chart="<?= $chart([
                'labels' => $counts['labels'], 'unit' => 'count',
                'datasets' => [
                    ['label' => 'Submitted', 'data' => $counts['submitted'], 'color' => '#2583e8'],
                    ['label' => 'Processed', 'data' => $counts['processed'], 'color' => '#f0a51b'],
                    ['label' => 'Paid', 'data' => $counts['paid'], 'color' => '#20a66a'],
                    ['label' => 'Submitted a year earlier', 'data' => $counts['prior'], 'color' => '#8a94a6', 'type' => 'line', 'dash' => true],
                ],
            ]) ?>"></canvas></div>
        </div>
    </div>

    <?php foreach ($money as $cur => $series): ?>
        <div class="card mb-3">
            <div class="card-header">Amount submitted and paid (<?= esc($cur) ?>)</div>
            <div class="card-body">
                <div class="chart-box"><canvas role="img" aria-label="Amount submitted and paid in <?= esc($cur, 'attr') ?> per <?= esc($f['gran'], 'attr') ?>" data-chart="<?= $chart([
                    'labels' => $series['labels'], 'unit' => 'money',
                    'datasets' => [
                        ['label' => 'Submitted', 'data' => $series['submitted'], 'color' => '#2583e8'],
                        ['label' => 'Paid', 'data' => $series['paid'], 'color' => '#20a66a'],
                        ['label' => 'Submitted a year earlier', 'data' => $series['prior'], 'color' => '#8a94a6', 'type' => 'line', 'dash' => true],
                    ],
                ]) ?>"></canvas></div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card mb-3">
        <div class="card-header">By period</div>
        <div class="table-responsive">
            <table class="table mg-table table-cards mb-0">
                <thead><tr><th>Period</th><th>Currency</th><th class="text-end">Submitted</th><th class="text-end">Submitted amount</th><th class="text-end">vs a year earlier</th><th class="text-end">Processed</th><th class="text-end">Paid</th><th class="text-end">Paid amount</th></tr></thead>
                <tbody>
                <?php $any = false; foreach (array_reverse($v['periods']) as $p): foreach ($v['rows'][$p] ?? [] as $cur => $m): $any = true;
                    $prior = $v['rows'][AnalyticsService::priorYear($p)][$cur]['submitted'][1] ?? 0;
                    $now   = $m['submitted'][1] ?? 0;
                    $yoy   = $prior > 0 ? round(($now - $prior) / $prior * 100) : null; ?>
                    <tr>
                        <td data-label="Period"><?= esc($p) ?></td>
                        <td data-label="Currency"><?= esc($cur) ?></td>
                        <td data-label="Submitted" class="text-end"><?= (int) ($m['submitted'][0] ?? 0) ?></td>
                        <td data-label="Submitted amount" class="text-end"><?= $amount((float) $now) ?></td>
                        <td data-label="vs a year earlier" class="text-end"><?= $yoy === null ? '—' : ($yoy > 0 ? '+' : '') . (int) $yoy . '%' ?></td>
                        <td data-label="Processed" class="text-end"><?= (int) ($m['processed'][0] ?? 0) ?></td>
                        <td data-label="Paid" class="text-end"><?= (int) ($m['paid'][0] ?? 0) ?></td>
                        <td data-label="Paid amount" class="text-end"><?= $amount((float) ($m['paid'][1] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; endforeach; ?>
                <?php if (! $any): ?><tr><td colspan="8" class="text-body-secondary">No invoices in this range.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php foreach ($top as $cur => $suppliers): ?>
        <div class="card mb-3">
            <div class="card-header">Largest suppliers by amount submitted (<?= esc($cur) ?>)</div>
            <div class="table-responsive">
                <table class="table mg-table table-cards mb-0">
                    <thead><tr><th>Supplier</th><th>Code</th><th class="text-end">Invoices</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td data-label="Supplier"><?= esc($s['supplier']) ?></td>
                            <td data-label="Code"><?= esc($s['code']) ?></td>
                            <td data-label="Invoices" class="text-end"><?= (int) $s['count'] ?></td>
                            <td data-label="Amount" class="text-end"><?= $amount((float) $s['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= base_url('assets/js/analytics-charts.js') ?>"></script>
<?= $this->endSection() ?>
