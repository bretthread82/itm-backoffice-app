<?php
/**
 * @var list<array<string, mixed>> $months ArReportService::collectionsByMonth()
 * @var string $currency the currency the chart is drawn in
 * @var list<string> $currencies
 * @var array<string, mixed> $stats
 * @var array<string, mixed> $f
 */
use App\Core\Payables\ReportService;

$money   = static fn (array $a): string => esc(ReportService::formatAmounts($a));
$onTime  = $stats['with_due'] > 0 ? (int) round($stats['on_time'] / $stats['with_due'] * 100) . '%' : '—';
$chart   = array_map(static fn (array $m): array => ['label' => $m['label'], 'invoiced' => (float) ($m['invoiced'][$currency] ?? 0), 'received' => (float) ($m['received'][$currency] ?? 0)], $months);
$thisMonth = end($months);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Collections',
    'headerSubtitle' => 'What was invoiced and what was received, month by month',
    'headerActions'  => '<a class="btn btn-outline-primary" href="' . site_url('receivables/reports/collections.csv') . '?' . esc(http_build_query($f)) . '"><i class="bi bi-filetype-csv me-1"></i>Download (CSV)</a>',
])->include('payables/components/page_header') ?>
<?= $this->setData(['reportTab' => 'collections'])->include('receivables/reports/_tabs') ?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Invoiced this month', '<span class="fs-5">' . $money($thisMonth['invoiced']) . '</span>', (int) $thisMonth['invoiced_count'] . ' invoices issued', 'bi-send', 'info', site_url('receivables')],
        ['Received this month', '<span class="fs-5">' . $money($thisMonth['received']) . '</span>', (int) $thisMonth['received_count'] . ' payments, after reversals', 'bi-cash-coin', 'success', site_url('receivables?status=paid')],
        ['Paid on time', $onTime, (int) $stats['paid'] . ' invoices paid in full', 'bi-check2-circle', 'success', site_url('receivables?status=paid')],
        ['Days to collect', $stats['avg_days'] === null ? '—' : esc((string) $stats['avg_days']) . ' d', 'on average, issue to paid in full', 'bi-speedometer2', 'info', site_url('receivables?status=paid')],
    ] as [$label, $value, $sub, $icon, $tone, $href]): ?>
        <div class="col-6 col-xl-3">
            <?= $this->setData(['statLabel' => $label, 'statValue' => $value, 'statSub' => $sub, 'statIcon' => $icon, 'statTone' => $tone, 'statHref' => $href])->include('payables/components/stat_card') ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mb-3">
    <div class="card-header">Invoiced and received<?= $currency !== '' ? ' in ' . esc($currency) : '' ?> <span class="small fw-normal text-body-secondary">(last 12 months)</span></div>
    <div class="card-body">
        <?php if ($currency === ''): ?>
            <div class="text-body-secondary">Nothing has been invoiced or received in this period.</div>
        <?php else: ?>
            <div class="chart-box"><canvas id="collections-chart" role="img" aria-label="Invoiced and received per month in <?= esc($currency) ?>" data-months="<?= esc(json_encode($chart), 'attr') ?>"></canvas></div>
            <p class="small text-body-secondary mt-2 mb-0">The chart shows one currency at a time because amounts are never added across currencies. Choose another above; the table shows them all.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">By month</div>
    <div class="table-responsive">
        <table class="table itm-table table-cards mb-0">
            <thead><tr><th>Month</th><th class="text-end">Invoices issued</th><th class="text-end">Invoiced</th><th class="text-end">Payments</th><th class="text-end">Received</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($months) as $m): ?>
                <tr>
                    <td data-label="Month"><?= esc($m['label']) ?></td>
                    <td data-label="Invoices issued" class="text-end"><?= (int) $m['invoiced_count'] ?></td>
                    <td data-label="Invoiced" class="text-end"><?= $money($m['invoiced']) ?></td>
                    <td data-label="Payments" class="text-end"><?= (int) $m['received_count'] ?></td>
                    <td data-label="Received" class="text-end fw-semibold"><?= $money($m['received']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($currency !== ''): ?>
    <script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/receivables-reports.js') ?>"></script>
<?php endif; ?>
<?= $this->endSection() ?>
