<?php
/**
 * @var array<string, mixed> $agg   ArReportService::aggregate()
 * @var array<string, mixed> $stats ArReportService::paymentStats()
 * @var array<string, mixed> $f
 */
use App\Core\Payables\ReportService;

$money   = static fn (array $a): string => esc(ReportService::formatAmounts($a));
$link    = array_filter(['company' => $f['company'] ?? 0]);
$qs      = static fn (array $extra): string => site_url('receivables') . '?' . http_build_query(array_filter($link + $extra, static fn ($v) => $v !== '' && $v !== null && $v !== 0));
$maxBand = max(1, ...array_values(array_map(static fn (array $b): int => $b['count'], $agg['bands'])));
$critical = $agg['critical']['count'];
$onTime   = $stats['with_due'] > 0 ? (int) round($stats['on_time'] / $stats['with_due'] * 100) . '%' : '—';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Customer invoice reports',
    'headerSubtitle' => 'What customers still owe, how late it is, and how it is being collected',
    'headerActions'  => '<a class="btn btn-outline-primary" href="' . site_url('receivables/reports/outstanding.csv') . '?' . esc(http_build_query($f)) . '"><i class="bi bi-filetype-csv me-1"></i>Download outstanding invoices (CSV)</a>',
])->include('payables/components/page_header') ?>
<?= $this->setData(['reportTab' => 'ageing'])->include('receivables/reports/_tabs') ?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Total outstanding', '<span class="fs-5">' . $money($agg['total']['amount']) . '</span>', (int) $agg['total']['count'] . ' invoices still owed', 'bi-cash-stack', 'info', $qs(['status' => 'collecting'])],
        ['Overdue', '<span class="fs-5">' . $money($agg['overdue']['amount']) . '</span>', (int) $agg['overdue']['count'] . ' invoices past their due date', 'bi-exclamation-triangle', $agg['overdue']['count'] > 0 ? 'warning' : 'success', $qs(['status' => 'overdue'])],
        ['Critical (61+ days)', '<span class="fs-5">' . $money($agg['critical']['amount']) . '</span>', (int) $critical . ' invoices', 'bi-x-octagon', $critical > 0 ? 'danger' : 'success', $qs(['status' => 'collecting', 'age' => 'critical'])],
        ['Paid on time', $onTime, $stats['avg_days'] === null ? (int) $stats['paid'] . ' paid invoices' : (int) $stats['paid'] . ' paid, on average ' . esc((string) $stats['avg_days']) . ' days after issue', 'bi-check2-circle', 'success', $qs(['status' => 'paid'])],
    ] as [$label, $value, $sub, $icon, $tone, $href]): ?>
        <div class="col-6 col-xl-3">
            <?= $this->setData(['statLabel' => $label, 'statValue' => $value, 'statSub' => $sub, 'statIcon' => $icon, 'statTone' => $tone, 'statHref' => $href])->include('payables/components/stat_card') ?>
        </div>
    <?php endforeach; ?>
</div>

<p class="small text-body-secondary mb-3">
    <strong>Outstanding</strong> is what issued and part-paid invoices still owe: the balance after payments, not the invoice amount.
    Age is counted from the day after the due date, on each invoice's company calendar. Amounts are shown per currency and never added across currencies.
</p>

<ul class="nav nav-tabs-mg mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#rep-overview" type="button" role="tab">Ageing</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rep-companies" type="button" role="tab">Companies</button></li>
    <?php if (count($agg['groups']) > 0): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rep-groups" type="button" role="tab">Sub-groups</button></li><?php endif; ?>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rep-overdue" type="button" role="tab">Most overdue</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="rep-overview" role="tabpanel">
        <div class="card mb-3">
            <div class="card-header">Outstanding by age <span class="small fw-normal text-body-secondary">(days past due date)</span></div>
            <div class="card-body">
                <?php foreach (ReportService::BANDS as $key => [$label, $colour]):
                    $b = $agg['bands'][$key];
                    $pct = $b['count'] === 0 ? 0 : max(3, (int) round($b['count'] / $maxBand * 100));
                    $isCritical = in_array($key, ['61-90', '90+'], true); ?>
                    <a class="bar-row" href="<?= $qs(['status' => 'collecting', 'age' => $key]) ?>">
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

    <div class="tab-pane fade" id="rep-companies" role="tabpanel">
        <div class="card mb-3">
            <div class="card-header">Outstanding by company</div>
            <?php if ($agg['companies'] === []): ?>
                <div class="card-body text-body-secondary">Nothing is outstanding.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table itm-table table-cards">
                        <thead><tr><th>Company</th><th class="text-end">Invoices</th><th class="text-end">Still owed</th><th class="text-end">Overdue</th><th class="text-end">Critical</th><th class="text-end">Oldest</th></tr></thead>
                        <tbody>
                        <?php foreach ($agg['companies'] as $c): ?>
                            <tr class="<?= $c['critical'] > 0 ? 'table-danger' : '' ?>">
                                <td data-label="Company"><a class="fw-semibold text-decoration-none" href="<?= site_url('receivables') . '?' . http_build_query(['company' => $c['company_id'], 'status' => 'collecting']) ?>" title="<?= esc($c['name']) ?>"><?= esc($c['code']) ?></a></td>
                                <td data-label="Invoices" class="text-end"><?= (int) $c['count'] ?></td>
                                <td data-label="Still owed" class="text-end"><?= $money($c['amount']) ?></td>
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

    <?php if (count($agg['groups']) > 0): ?>
        <div class="tab-pane fade" id="rep-groups" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header">Outstanding by sub-group <span class="small fw-normal text-body-secondary">(for reporting only; sub-groups never affect who can see what)</span></div>
                <div class="table-responsive">
                    <table class="table itm-table table-cards">
                        <thead><tr><th>Sub-group</th><th class="text-end">Invoices</th><th class="text-end">Still owed</th><th class="text-end">Overdue</th><th class="text-end">Critical</th></tr></thead>
                        <tbody>
                        <?php foreach ($agg['groups'] as $g): ?>
                            <tr>
                                <td data-label="Sub-group"><?= esc($g['name']) ?></td>
                                <td data-label="Invoices" class="text-end"><?= (int) $g['count'] ?></td>
                                <td data-label="Still owed" class="text-end"><?= $money($g['amount']) ?></td>
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

    <div class="tab-pane fade" id="rep-overdue" role="tabpanel">
        <div class="card mb-3 <?= $critical > 0 ? 'border-danger' : '' ?>">
            <div class="card-header d-flex justify-content-between"><span>Most overdue</span>
                <a class="small fw-normal" href="<?= $qs(['status' => 'overdue']) ?>">All overdue</a></div>
            <?php if ($agg['oldest'] === []): ?>
                <div class="card-body text-body-secondary">No outstanding invoice is past its due date.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table itm-table table-cards">
                        <thead><tr><th>Invoice</th><th>Company</th><th>Customer</th><th class="text-end">Still owed</th><th>Due</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($agg['oldest'] as $r): ?>
                            <tr class="<?= ReportService::isCritical((int) $r['days_past_due']) ? 'table-danger' : '' ?>">
                                <td data-label="Invoice"><a class="fw-semibold text-decoration-none" href="<?= site_url('receivables/' . $r['id']) ?>"><?= esc($r['invoice_number'] ?: '#' . $r['id']) ?></a></td>
                                <td data-label="Company"><?= esc($r['company_code']) ?></td>
                                <td data-label="Customer"><?= esc($r['customer'] ?: '—') ?></td>
                                <td data-label="Still owed" class="text-end"><?= invoice_money($r['balance'], $r['currency']) ?></td>
                                <td data-label="Due"><?= esc($r['due_date']) ?> <?= invoice_age_badge((int) $r['days_past_due']) ?></td>
                                <td data-label="Status"><?= ar_status_badge($r['status'], (int) $r['days_past_due']) ?></td>
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
