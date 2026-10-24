<?php
/**
 * @var array<string, mixed> $agg
 * @var list<array<string, mixed>> $trend
 */
use App\Core\Payables\ReportService;

$first  = trim(explode(' ', display_name())[0]);
$money  = static fn (array $a): string => esc(ReportService::formatAmounts($a));
$qs     = static fn (array $extra): string => site_url('payables') . '?' . http_build_query(array_filter($link + $extra, static fn ($v) => $v !== '' && $v !== null && $v !== 0));
$critical = $agg['critical']['count'];

$where = $companyName ?? ($groupLevel ? 'all entities' : (count($companies) > 1 ? 'all your companies' : ($companies[0]['name'] ?? 'your company')));
$maxCo = max(1, ...array_values(array_map(static fn (array $c): int => $c["count"], $agg["companies"] ?: [["count" => 0]])));
$maxBand = max(1, ...array_values(array_map(static fn (array $b): int => $b['count'], $agg['bands'])));

$tasks = [];
if (count($waiting) > 0) {
    $tasks[] = ['warning', count($waiting), 'Waiting for your approval', site_url('approvals')];
}
if ($over30 > 0) {
    $tasks[] = ['danger', $over30, 'invoices overdue by more than 30 days', $qs(['status' => 'outstanding', 'age' => 'overdue'])];
}
if (count($mine['needs_action']) > 0) {
    $tasks[] = ['danger', count($mine['needs_action']), 'of your invoices need changes', site_url('approvals')];
}
if (count($mine['waiting']) > 0) {
    $tasks[] = ['info', count($mine['waiting']), 'of your invoices are in approval', site_url('approvals')];
}
if (count($mine['drafts']) > 0) {
    $tasks[] = ['draft', count($mine['drafts']), 'of your drafts not yet submitted', site_url('approvals')];
}
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <?php if ($groupLevel && $companyName === null): ?>
                <h1>Group overview</h1>
                <p>Financial operations and invoice activity across all entities</p>
            <?php else: ?>
                <h1>Welcome back<?= $first !== '' ? ', ' . esc($first) : '' ?></h1>
                <p>Here's what's happening with invoices at <?= esc($where) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 my-3 px-lg-3 dashboard-actions">
    <?php if ($canSubmit): ?><a class="btn btn-primary btn-lg" href="<?= site_url('payables/new') ?>"><i class="bi bi-plus-lg me-1"></i>New invoice</a><?php endif; ?>
    <?php if (can('payables.reports')): ?><a class="btn btn-outline-primary btn-lg" href="<?= site_url('reports') ?>"><i class="bi bi-bar-chart-line me-1"></i>Reports</a><?php endif; ?>
</div>

<?php if ($companies === []): ?>
    <div class="card hero-kpis mt-4"><div class="card-body text-body-secondary">You are not a member of any company yet. Ask an administrator to add you.</div></div>
<?php else: ?>
    <div class="row g-3 hero-kpis mb-3 px-lg-3">
        <?php
        $cards = [
            ['Total Invoiced (this year)', $money($ytd), $ytdChange === null ? 'submitted so far this year' : ($ytdChange >= 0 ? '<i class="bi bi-arrow-up"></i> ' : '<i class="bi bi-arrow-down"></i> ') . abs((int) $ytdChange) . '% vs same period last year', 'bi-file-earmark-text', 'info', $qs([])],
            ['Awaiting Approval', $money($awaiting['amount']), (int) $awaiting['count'] . ' invoices', 'bi-hourglass-split', 'warning', $qs(['status' => 'open'])],
            ['Overdue', $money($agg['overdue']['amount']), (int) $agg['overdue']['count'] . ' invoices' . ($critical > 0 ? ' · <strong class="text-danger">' . (int) $critical . ' critical (61+ days)</strong>' : ''), 'bi-exclamation-circle', 'danger', $qs(['status' => 'outstanding', 'age' => 'overdue'])],
            ['Due in Next 30 Days', $money($dueSoon['amount']), (int) $dueSoon['count'] . ' invoices', 'bi-calendar-check', 'success', $qs(['status' => 'outstanding', 'age' => 'current'])],
        ];
        foreach ($cards as [$label, $value, $sub, $icon, $tone, $href]): ?>
            <div class="col-md-6 col-xl-3">
                <?= $this->setData(['statLabel' => $label, 'statValue' => '<span class="fs-5">' . $value . '</span>', 'statSub' => $sub, 'statIcon' => $icon, 'statTone' => $tone, 'statHref' => $href])->include('payables/components/stat_card') ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">Invoice Status Trend</div>
                <div class="card-body">
                    <div class="chart-box"><canvas id="trend-chart" aria-label="Invoices per month" role="img" data-trend="<?= esc(json_encode($trend), 'attr') ?>"></canvas></div>
                    <noscript><table class="table table-sm"><thead><tr><th>Month</th><th>Submitted</th><th>Completed</th><th>Outstanding</th><th>Overdue</th></tr></thead><tbody>
                        <?php foreach ($trend as $t): ?><tr><td><?= esc($t['label']) ?></td><td><?= (int) $t['submitted'] ?></td><td><?= (int) $t['completed'] ?></td><td><?= (int) $t['pending'] ?></td><td><?= (int) $t['overdue'] ?></td></tr><?php endforeach; ?>
                    </tbody></table></noscript>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between"><span>Outstanding by company</span><span class="small fw-normal text-body-secondary">Outstanding · <?= $money($agg['total']['amount']) ?></span></div>
                <div class="card-body">
                    <?php if ($agg['companies'] === []): ?>
                        <div class="text-body-secondary">Nothing is outstanding.</div>
                    <?php endif; ?>
                    <?php foreach (array_slice($agg['companies'], 0, 8, true) as $c): ?>
                        <a class="bar-row" href="<?= site_url('payables') . '?' . http_build_query(['company' => $c['company_id'], 'status' => 'outstanding']) ?>" title="<?= esc($c['name']) ?>">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold"><?= esc($c['code']) ?> <span class="text-body-secondary fw-normal"><?= (int) $c['count'] ?> invoices</span><?= $c['critical'] > 0 ? ' <span class="badge text-bg-danger">' . (int) $c['critical'] . ' critical</span>' : '' ?></span>
                                <span><?= $money($c['amount']) ?></span>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= max(3, (int) round($c['count'] / $maxCo * 100)) ?>%"></div></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between"><span>My Tasks &amp; Approvals</span><a class="small fw-normal" href="<?= site_url('approvals') ?>">View All</a></div>
                <div class="card-body py-2">
                    <?php if ($tasks === []): ?><div class="text-body-secondary small py-2">Nothing needs your attention right now.</div><?php endif; ?>
                    <?php foreach ($tasks as [$tone, $n, $text, $href]): ?>
                        <a class="task-row" href="<?= esc($href) ?>">
                            <span class="dot tone-<?= $tone ?>"><?= (int) $n ?></span>
                            <span class="small"><?= $n === 1 && ! str_starts_with($text, 'Waiting') ? esc(preg_replace('/^invoices/', 'invoice', $text) ?? $text) : esc($text) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">Outstanding by age <span class="small fw-normal text-body-secondary">(days past due)</span></div>
                <div class="card-body">
                    <?php foreach (ReportService::BANDS as $key => [$label, $colour]):
                        $b = $agg['bands'][$key];
                        $pct = $b['count'] === 0 ? 0 : max(3, (int) round($b['count'] / $maxBand * 100)); ?>
                        <a class="bar-row" href="<?= $qs(['status' => 'outstanding', 'age' => $key]) ?>">
                            <div class="d-flex justify-content-between small mb-1">
                                <span><?= esc($label) ?><?= in_array($key, ['61-90', '90+'], true) ? ' <span class="badge text-bg-danger">Critical</span>' : '' ?></span>
                                <span><strong><?= (int) $b['count'] ?></strong> · <?= $money($b['amount']) ?></span>
                            </div>
                            <div class="bar-track"><div class="bar-fill bg-<?= $colour ?>" data-width="<?= $pct ?>"></div></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card h-100 <?= $critical > 0 ? 'border-danger' : '' ?>">
                <div class="card-header d-flex justify-content-between"><span>Most overdue</span><a class="small fw-normal" href="<?= $qs(['status' => 'outstanding', 'age' => 'overdue']) ?>">All overdue</a></div>
                <?php if ($agg['oldest'] === []): ?>
                    <div class="card-body text-body-secondary">No outstanding invoice is past its due date.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table itm-table table-cards">
                            <thead><tr><th>Invoice</th><th>Supplier</th><th class="text-end">Amount</th><th>Due</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($agg['oldest'], 0, 6) as $r): ?>
                                <tr class="<?= ReportService::isCritical((int) $r['days_past_due']) ? 'table-danger' : '' ?>">
                                    <td data-label="Invoice"><a class="fw-semibold text-decoration-none" href="<?= site_url('payables/' . $r['id']) ?>"><?= esc($r['invoice_number'] ?: '#' . $r['id']) ?></a> <span class="small text-body-secondary"><?= esc($r['company_code']) ?></span></td>
                                    <td data-label="Supplier"><?= esc($r['supplier'] ?: '—') ?></td>
                                    <td data-label="Amount" class="text-end"><?= invoice_money($r['amount'], $r['currency']) ?></td>
                                    <td data-label="Due"><?= esc($r['due_date']) ?> <?= invoice_age_badge((int) $r['days_past_due']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-xl-3">
            <div class="card h-100">
                <div class="card-header">Recent Activity</div>
                <div class="card-body py-2"><?= $this->setData(['feedItems' => $activity, 'feedVisible' => 5])->include('payables/components/activity_feed') ?></div>
            </div>
        </div>
    </div>

    <?php if ($groupLevel): ?>
        <h2 class="h6 text-body-secondary text-uppercase">All companies <span class="badge text-bg-primary ms-1"><i class="bi bi-shield-lock me-1"></i>Group-level</span></h2>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php foreach ($companies as $c): ?>
            <a class="badge text-bg-light border text-decoration-none" href="<?= site_url('payables?company=' . $c['id']) ?>" title="<?= esc($c['name']) ?>"><?= esc($c['code']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
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
