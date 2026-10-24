<?php
/**
 * @var array<string, mixed> $agg      ReportService::aggregate()
 * @var array<string, int>   $counts   invoice counts by status
 * @var array<string, mixed> $stats    ReportService::completionStats()
 * @var list<array<string, mixed>> $trend
 * @var array<string, mixed> $f
 * @var array<string, mixed> $link     extra query values for links (company)
 */
use App\Core\Payables\ReportService;

$money   = static fn (array $a): string => esc(ReportService::formatAmounts($a));
$qs      = static fn (array $extra): string => site_url('payables') . '?' . http_build_query(array_filter($link + $extra, static fn ($v) => $v !== '' && $v !== null && $v !== 0));
$maxBand = max(1, ...array_values(array_map(static fn (array $b): int => $b['count'], $agg['bands'])));
$submitted = ReportService::totalSubmitted($counts);
$critical  = $agg['critical']['count'];
$onTime    = $stats['with_due'] > 0 ? (int) round($stats['on_time'] / $stats['with_due'] * 100) . '%' : '—';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Reports & Analytics',
    'headerSubtitle' => 'Outstanding invoices, ageing and how invoices are moving',
    'headerActions'  => '<a class="btn btn-outline-primary" href="' . site_url('reports/outstanding.csv') . '?' . esc(http_build_query($f)) . '"><i class="bi bi-filetype-csv me-1"></i>Download outstanding invoices (CSV)</a>',
])->include('payables/components/page_header') ?>
<?= $this->setData(['reportTab' => 'outstanding', 'reportTabQuery' => $f])->include('payables/components/report_tabs') ?>

<div class="card mb-3">
    <form method="get" class="filter-bar">
        <select class="form-select" name="company" aria-label="Company">
            <option value="">All my companies</option>
            <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (int) ($f['company'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?> · <?= esc($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select" name="group" aria-label="Sub-group">
            <option value="">All sub-groups</option>
            <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>" <?= (int) ($f['group'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Apply</button>
        <?php if ($f !== []): ?><a class="btn btn-link" href="<?= site_url('reports') ?>">Clear</a><?php endif; ?>
    </form>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Total Outstanding', '<span class="fs-5">' . $money($agg['total']['amount']) . '</span>', 'submitted and not yet completed', 'bi-cash-stack', 'info', $qs(['status' => 'outstanding'])],
        ['Open Invoices', (string) (int) $agg['total']['count'], (int) $submitted . ' submitted in total', 'bi-files', 'warning', $qs(['status' => 'outstanding'])],
        ['Avg Days to Complete', $stats['avg_days'] === null ? '—' : esc((string) $stats['avg_days']) . ' d', (int) $stats['completed'] . ' completed invoices', 'bi-speedometer2', 'success', $qs(['status' => 'completed'])],
        ['Completed on Time', $onTime, 'completed on or before the due date', 'bi-check2-circle', $critical > 0 ? 'danger' : 'success', $qs(['status' => 'outstanding', 'age' => 'critical'])],
    ] as [$label, $value, $sub, $icon, $tone, $href]): ?>
        <div class="col-6 col-xl-3">
            <?= $this->setData(['statLabel' => $label, 'statValue' => $value, 'statSub' => $sub, 'statIcon' => $icon, 'statTone' => $tone, 'statHref' => $href])->include('payables/components/stat_card') ?>
        </div>
    <?php endforeach; ?>
</div>

<p class="small text-body-secondary mb-3">
    <strong>Outstanding</strong> means submitted and not yet completed: in approval, being processed, or returned for changes.
    Amounts are shown per currency and never added across currencies.
    <?php if ($counts !== []): ?>
        <span class="d-block mt-1">
            <?php foreach (['in_approval' => 'In approval', 'approved' => 'Being processed', 'returned' => 'Returned', 'processed' => 'Processed', 'paid' => 'Paid', 'rejected' => 'Rejected'] as $k => $label): ?>
                <span class="me-3"><?= esc($label) ?>: <strong><?= (int) ($counts[$k] ?? 0) ?></strong></span>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>
</p>

<ul class="nav nav-tabs-mg mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#rep-overview" type="button" role="tab">Overview</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rep-companies" type="button" role="tab">Companies</button></li>
    <?php if (count($agg['groups']) > 0): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rep-groups" type="button" role="tab">Sub-groups</button></li><?php endif; ?>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rep-overdue" type="button" role="tab">Most overdue</button></li>
</ul>

<div class="tab-content">
    <!-- Overview: how old is the backlog, and how is volume trending -->
    <div class="tab-pane fade show active" id="rep-overview" role="tabpanel">
        <div class="row g-3 mb-3">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">Outstanding by age <span class="small fw-normal text-body-secondary">(days past due date)</span></div>
                    <div class="card-body">
                        <?php foreach (ReportService::BANDS as $key => [$label, $colour]):
                            $b = $agg['bands'][$key];
                            $pct = $b['count'] === 0 ? 0 : max(3, (int) round($b['count'] / $maxBand * 100));
                            $isCritical = in_array($key, ['61-90', '90+'], true); ?>
                            <a class="bar-row" href="<?= $qs(['status' => 'outstanding', 'age' => $key]) ?>">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span><?= esc($label) ?><?= $isCritical ? ' <span class="badge text-bg-danger">Critical</span>' : '' ?></span>
                                    <span><strong><?= (int) $b['count'] ?></strong> · <?= $money($b['amount']) ?></span>
                                </div>
                                <div class="bar-track" role="progressbar" aria-label="<?= esc($label) ?>" aria-valuemin="0" aria-valuemax="<?= (int) $maxBand ?>" aria-valuenow="<?= (int) $b['count'] ?>"><div class="bar-fill bg-<?= $colour ?>" data-width="<?= $pct ?>"></div></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">Invoices per month</div>
                    <div class="card-body">
                        <div class="chart-box"><canvas id="trend-chart" role="img" aria-label="Invoices per month" data-trend="<?= esc(json_encode($trend), 'attr') ?>"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Companies -->
    <div class="tab-pane fade" id="rep-companies" role="tabpanel">
        <div class="card mb-3">
            <div class="card-header">Outstanding by company</div>
            <?php if ($agg['companies'] === []): ?>
                <div class="card-body text-body-secondary">Nothing is outstanding.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table itm-table table-cards">
                        <thead><tr><th>Company</th><th class="text-end">Invoices</th><th class="text-end">Amount</th><th class="text-end">Overdue</th><th class="text-end">Critical</th><th class="text-end">Oldest</th></tr></thead>
                        <tbody>
                        <?php foreach ($agg['companies'] as $c): ?>
                            <tr class="<?= $c['critical'] > 0 ? 'table-danger' : '' ?>">
                                <td data-label="Company"><a class="fw-semibold text-decoration-none" href="<?= site_url('payables') . '?' . http_build_query(['company' => $c['company_id'], 'status' => 'outstanding']) ?>" title="<?= esc($c['name']) ?>"><?= esc($c['code']) ?></a></td>
                                <td data-label="Invoices" class="text-end"><?= (int) $c['count'] ?></td>
                                <td data-label="Amount" class="text-end"><?= $money($c['amount']) ?></td>
                                <td data-label="Overdue" class="text-end"><?= (int) $c['overdue'] ?></td>
                                <td data-label="Critical" class="text-end"><?= $c['critical'] > 0 ? '<span class="badge text-bg-danger">' . (int) $c['critical'] . '</span>' : '0' ?></td>
                                <td data-label="Oldest" class="text-end"><?= $c['worst'] > 0 ? (int) $c['worst'] . ' d' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sub-groups (reporting only; they never affect who can see what) -->
    <?php if (count($agg['groups']) > 0): ?>
        <div class="tab-pane fade" id="rep-groups" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header">Outstanding by sub-group <span class="small fw-normal text-body-secondary">(for reporting only; sub-groups never affect who can see what)</span></div>
                <div class="table-responsive">
                    <table class="table itm-table table-cards">
                        <thead><tr><th>Sub-group</th><th class="text-end">Invoices</th><th class="text-end">Amount</th><th class="text-end">Overdue</th><th class="text-end">Critical</th></tr></thead>
                        <tbody>
                        <?php foreach ($agg['groups'] as $g): ?>
                            <tr>
                                <td data-label="Sub-group"><?= esc($g['name']) ?></td>
                                <td data-label="Invoices" class="text-end"><?= (int) $g['count'] ?></td>
                                <td data-label="Amount" class="text-end"><?= $money($g['amount']) ?></td>
                                <td data-label="Overdue" class="text-end"><?= (int) $g['overdue'] ?></td>
                                <td data-label="Critical" class="text-end"><?= $g['critical'] > 0 ? '<span class="badge text-bg-danger">' . (int) $g['critical'] . '</span>' : '0' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Most overdue -->
    <div class="tab-pane fade" id="rep-overdue" role="tabpanel">
        <div class="card mb-3 <?= $critical > 0 ? 'border-danger' : '' ?>">
            <div class="card-header d-flex justify-content-between"><span>Most overdue</span>
                <a class="small fw-normal" href="<?= $qs(['status' => 'outstanding', 'age' => 'overdue']) ?>">All overdue</a></div>
            <?php if ($agg['oldest'] === []): ?>
                <div class="card-body text-body-secondary">No outstanding invoice is past its due date.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table itm-table table-cards">
                        <thead><tr><th>Invoice</th><th>Company</th><th>Supplier</th><th class="text-end">Amount</th><th>Due</th><th>With</th></tr></thead>
                        <tbody>
                        <?php foreach ($agg['oldest'] as $r): ?>
                            <tr class="<?= ReportService::isCritical((int) $r['days_past_due']) ? 'table-danger' : '' ?>">
                                <td data-label="Invoice"><a class="fw-semibold text-decoration-none" href="<?= site_url('payables/' . $r['id']) ?>"><?= esc($r['invoice_number'] ?: '#' . $r['id']) ?></a></td>
                                <td data-label="Company"><?= esc($r['company_code']) ?></td>
                                <td data-label="Supplier"><?= esc($r['supplier'] ?: '—') ?></td>
                                <td data-label="Amount" class="text-end"><?= invoice_money($r['amount'], $r['currency']) ?></td>
                                <td data-label="Due"><?= esc($r['due_date']) ?> <?= invoice_age_badge((int) $r['days_past_due']) ?></td>
                                <td data-label="With" class="small"><?= esc($r['step_label'] ?: ucfirst(str_replace('_', ' ', (string) $r['status']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script {csp-script-nonce}>
(function () {
    var el = document.getElementById('trend-chart');
    if (!el || typeof Chart === 'undefined') { return; }
    var t = JSON.parse(el.dataset.trend || '[]');
    new Chart(el, {
        type: 'bar',
        data: {
            labels: t.map(function (m) { return m.label; }),
            datasets: [
                { label: 'Submitted', data: t.map(function (m) { return m.submitted; }), backgroundColor: '#2583e8' },
                { label: 'Completed', data: t.map(function (m) { return m.completed; }), backgroundColor: '#20a66a' },
                { label: 'Still outstanding', data: t.map(function (m) { return m.pending; }), backgroundColor: '#f0a51b' },
                { label: 'Overdue', data: t.map(function (m) { return m.overdue; }), backgroundColor: '#e34850' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }
        }
    });
})();
</script>
<?= $this->endSection() ?>
