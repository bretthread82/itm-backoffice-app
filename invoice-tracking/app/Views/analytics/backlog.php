<?php
/**
 * @var array{points: list<array{date: string, estimated: bool, by: array<string, array<string, array{0: int, 1: float}>>}>, currencies: list<string>} $b
 * @var string                                                                                                                                         $currency selected currency
 * @var array<string, mixed>                                                                                                                           $f
 */

use App\Services\ReportService;

$ok       = ! $error && $status['has_data'];
$currency = $currency ?? '';
$chart  = static fn (array $spec): string => esc(json_encode($spec, JSON_UNESCAPED_UNICODE), 'attr');
$colours = ['current' => '#20a66a', '1-30' => '#f0a51b', '31-60' => '#e8832a', '61-90' => '#e34850', '90+' => '#8e1f2b'];
$points  = $ok ? $b['points'] : [];
$labels  = array_map(static fn (array $p): string => $p['date'] . ($p['estimated'] ? ' (est.)' : ''), $points);
$series  = static function (int $i) use ($points, $currency, $colours): array {
    $sets = [];
    foreach ($colours as $band => $colour) {
        $sets[] = [
            'label' => ReportService::BANDS[$band][0], 'color' => $colour, 'stack' => 'backlog',
            'data'  => array_map(static fn (array $p) => $p['by'][$currency][$band][$i] ?? 0, $points),
        ];
    }

    return $sets;
};
$currencyUrl = static fn (string $cur): string => site_url('admin/analytics/backlog') . '?' . http_build_query(array_filter($f, static fn ($v) => $v !== '' && $v !== 0) + ['currency' => $cur]);
$anyEstimate = in_array(true, array_column($points, 'estimated'), true);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData(['tab' => 'backlog', 'csvPath' => 'admin/analytics/backlog.csv'])->include('analytics/_top') ?>

<?php if ($ok): ?>
    <?php if ($points === []): ?>
        <div class="alert alert-info">No backlog snapshots fall in this date range.</div>
    <?php else: ?>
        <?php if (count($b['currencies']) > 1): ?>
            <ul class="nav nav-pills mb-3" aria-label="Currency">
                <?php foreach ($b['currencies'] as $cur): ?><li class="nav-item"><a class="nav-link <?= $cur === $currency ? 'active' : '' ?>" <?= $cur === $currency ? 'aria-current="page"' : '' ?> href="<?= esc($currencyUrl($cur), 'attr') ?>"><?= esc($cur) ?></a></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($anyEstimate): ?>
            <p class="small text-body-secondary mb-3"><i class="bi bi-info-circle me-1"></i>Points marked <strong>(est.)</strong> were rebuilt from each invoice's submit and finish dates. They can differ slightly from what was on screen that day, because later edits to due dates or amounts cannot be replayed. Points without the mark were recorded nightly.</p>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Outstanding invoices by age (<?= esc($currency) ?>)</div>
                    <div class="card-body"><div class="chart-box"><canvas role="img" aria-label="Number of outstanding invoices by ageing band over time" data-chart="<?= $chart(['labels' => $labels, 'unit' => 'count', 'stacked' => true, 'datasets' => $series(0)]) ?>"></canvas></div></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Outstanding amount by age (<?= esc($currency) ?>)</div>
                    <div class="card-body"><div class="chart-box"><canvas role="img" aria-label="Outstanding amount by ageing band over time" data-chart="<?= $chart(['labels' => $labels, 'unit' => 'money', 'stacked' => true, 'datasets' => $series(1)]) ?>"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Backlog at each point (<?= esc($currency) ?>)</div>
            <div class="table-responsive">
                <table class="table mg-table mb-0">
                    <thead><tr><th>Date</th><?php foreach (ReportService::BANDS as [$label]): ?><th class="text-end"><?= esc($label) ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($points) as $p): $tn = 0; $ta = 0.0; ?>
                        <tr>
                            <td><?= esc($p['date']) ?><?= $p['estimated'] ? ' <span class="text-body-secondary">(est.)</span>' : '' ?></td>
                            <?php foreach (array_keys(ReportService::BANDS) as $band): [$n, $a] = $p['by'][$currency][$band] ?? [0, 0.0]; $tn += $n; $ta += $a; ?>
                                <td class="text-end"><?= (int) $n ?><span class="d-block small text-body-secondary"><?= number_format((float) $a, 2) ?></span></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-semibold"><?= (int) $tn ?><span class="d-block small text-body-secondary"><?= number_format($ta, 2) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-body-secondary">Each cell is the number of invoices over the amount. The Not yet due column is invoices still inside their payment terms.</div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= base_url('assets/js/analytics-charts.js') ?>"></script>
<?= $this->endSection() ?>
