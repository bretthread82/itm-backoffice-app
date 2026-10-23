<?php
/**
 * @var list<array<string, mixed>> $outstanding ReportService::outstandingBySupplier()
 * @var array<string, mixed>       $totals      ReportService::totalsBySupplier()
 * @var array<string, string>      $codes       supplier code by lower-case name
 * @var string                     $type        week | month | quarter | year
 * @var string                     $anchor      date the last period contains
 * @var array<string, mixed>       $f
 */
use App\Services\ReportService;

$money   = static fn (array $a): string => esc(ReportService::formatAmounts($a));
$code    = static fn (string $name): string => $codes[mb_strtolower($name)] ?? '';
$label   = static fn (string $name): string => ($code($name) !== '' ? $code($name) . ' · ' : '') . $name;
$types   = ['week' => 'Weekly', 'month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Yearly'];
$csvBase = ['company' => $f['company'] ?? '', 'group' => $f['group'] ?? ''];
$csv     = static fn (array $extra): string => site_url('reports/suppliers.csv') . '?' . esc(http_build_query(array_filter($csvBase + $extra, static fn ($v) => $v !== '' && $v !== null)));
$periods = $totals['periods'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Reports & Analytics',
    'headerSubtitle' => 'What is outstanding with each supplier, and how many invoices each supplier sent',
    'headerActions'  => '',
])->include('components/page_header') ?>
<?= $this->setData(['reportTab' => 'suppliers', 'reportTabQuery' => $f])->include('components/report_tabs') ?>

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
        <select class="form-select" name="period" aria-label="Period">
            <?php foreach ($types as $k => $name): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?>
        </select>
        <input class="form-control" type="date" name="date" max="<?= date('Y-m-d') ?>" value="<?= esc($anchor) ?>" aria-label="Show the periods ending with the one containing this date">
        <button class="btn btn-primary" type="submit">Apply</button>
        <?php if ($f !== [] || $type !== 'month' || $anchor !== date('Y-m-d')): ?><a class="btn btn-link" href="<?= site_url('reports/suppliers') ?>">Clear</a><?php endif; ?>
    </form>
</div>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Outstanding invoices by supplier</span>
        <a class="btn btn-sm btn-outline-primary" href="<?= $csv(['report' => 'outstanding']) ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
    </div>
    <?php if ($outstanding === []): ?>
        <div class="card-body text-body-secondary">Nothing is outstanding.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table mg-table table-cards mb-0">
                <thead><tr><th>Supplier</th><th class="text-end">Invoices</th><th class="text-end">Amount</th><th class="text-end">Overdue</th><th class="text-end">Critical</th><th class="text-end">Most days past due</th></tr></thead>
                <tbody>
                <?php foreach ($outstanding as $r): ?>
                    <tr>
                        <td data-label="Supplier"><a href="<?= site_url('invoices') . '?' . esc(http_build_query(array_filter(['status' => 'outstanding', 'q' => $r['supplier'] === ReportService::NO_SUPPLIER ? '' : $r['supplier'], 'company' => $f['company'] ?? 0]))) ?>"><?= esc($label($r['supplier'])) ?></a></td>
                        <td data-label="Invoices" class="text-end"><?= (int) $r['count'] ?></td>
                        <td data-label="Amount" class="text-end"><?= $money($r['amount']) ?></td>
                        <td data-label="Overdue" class="text-end"><?= (int) $r['overdue'] ?></td>
                        <td data-label="Critical" class="text-end"><?= $r['critical'] > 0 ? '<span class="badge text-bg-danger">' . (int) $r['critical'] . '</span>' : '0' ?></td>
                        <td data-label="Most days past due" class="text-end"><?= $r['worst'] > 0 ? (int) $r['worst'] : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><?= esc($types[$type]) ?> invoices by supplier</span>
        <a class="btn btn-sm btn-outline-primary" href="<?= $csv(['report' => 'totals', 'period' => $type, 'date' => $anchor]) ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
    </div>
    <?php if ($totals['suppliers'] === []): ?>
        <div class="card-body text-body-secondary">No invoices were submitted in these periods.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table mg-table mb-0">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <?php foreach ($periods as $p): ?><th class="text-end text-nowrap"><?= esc($p['label']) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($totals['suppliers'] as $r): ?>
                    <tr>
                        <td><?= esc($label($r['supplier'])) ?></td>
                        <?php foreach ($r['cells'] as $cell): ?>
                            <td class="text-end"><?php if ($cell['count'] === 0): ?><span class="text-body-secondary">—</span><?php else: ?><strong><?= (int) $cell['count'] ?></strong><div class="small text-body-secondary text-nowrap"><?= $money($cell['amount']) ?></div><?php endif; ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td>All suppliers</td>
                        <?php foreach ($totals['totals'] as $cell): ?>
                            <td class="text-end"><?= (int) $cell['count'] ?><div class="small fw-normal text-body-secondary text-nowrap"><?= $money($cell['amount']) ?></div></td>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
    <div class="card-footer small text-body-secondary">
        Counts invoices by the date they were submitted; drafts and cancelled invoices are left out. Weeks start on Monday. Amounts are shown per currency and never added across currencies.
    </div>
</div>
<?= $this->endSection() ?>
