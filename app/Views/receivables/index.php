<?php
$me        = (int) auth()->id();
$multi     = count($companies) > 1;
$qs        = static fn (array $over): string => http_build_query(array_filter($over + $f, static fn ($v) => $v !== '' && $v !== null && $v !== 0));
$sortLink  = static function (string $key, string $label) use ($f, $qs): string {
    $dir  = $f['sort'] === $key && $f['dir'] === 'asc' ? 'desc' : 'asc';
    $icon = $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';

    return '<a href="' . site_url('receivables') . '?' . esc($qs(['sort' => $key, 'dir' => $dir, 'page' => 0])) . '">' . esc($label) . $icon . '</a>';
};
$from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to   = min($total, $page * $perPage);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Customer invoices',
    'headerSubtitle' => 'Invoices we issue to customers, and the money still to collect',
    'headerActions'  => ($canSubmit ? '<a class="btn btn-primary" href="' . site_url('receivables/new') . '"><i class="bi bi-plus-lg me-1"></i>New customer invoice</a>' : ''),
])->include('payables/components/page_header') ?>

<?php
$sum  = static fn (array $b): string => '<span class="fs-5">' . esc(\App\Core\Payables\ReportService::formatAmounts($b['amount'])) . '</span>';
$subN = static fn (array $b): string => (int) $b['count'] . ($b['count'] === 1 ? ' invoice' : ' invoices');
$here = site_url('receivables') . '?' . esc($qs(['page' => 0]));
?>
<div class="row g-3 mb-1">
    <?php foreach ([
        ['Outstanding', $summary['outstanding'], 'bi-hourglass-split', 'warning', site_url('receivables') . '?status=collecting'],
        ['Overdue', $summary['overdue'], 'bi-exclamation-triangle', 'danger', site_url('receivables') . '?status=overdue'],
        ['Received', $summary['received'], 'bi-check2-circle', 'success', $here],
    ] as [$label, $bucket, $icon, $tone, $href]): ?>
        <div class="col-12 col-md-4">
            <?= $this->setData(['statLabel' => $label, 'statValue' => $sum($bucket), 'statSub' => $subN($bucket), 'statIcon' => $icon, 'statTone' => $tone, 'statHref' => $href])->include('payables/components/stat_card') ?>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-body-secondary mb-3">Based on the filters below, ignoring the status filter. Outstanding is what issued invoices still owe; overdue is the part of it past its due date; received is everything paid so far. Amounts are per currency.</p>

<div class="card">
    <form method="get" class="filter-bar" action="<?= site_url('receivables') ?>">
        <div class="grow"><input class="form-control w-100" type="search" name="q" value="<?= esc($f['q']) ?>" placeholder="Invoice number, customer or customer PO" aria-label="Search"></div>
        <select class="form-select" name="status" aria-label="Status">
            <?php foreach (\App\Core\Receivables\ArQuery::STATUS_FILTERS as $v => $l): ?>
                <option value="<?= $v ?>" <?= $f['status'] === $v ? 'selected' : '' ?>><?= esc($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($multi): ?>
            <select class="form-select" name="company" aria-label="Company">
                <option value="">All companies</option>
                <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $f['company'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select class="form-select" name="age" aria-label="Past due">
            <?php foreach (['' => 'Any due date', 'overdue' => 'Overdue (1+ days)', 'current' => 'Not yet due', '1-30' => '1 to 30 days late', '31-60' => '31 to 60 days late', '61-90' => '61 to 90 days late', '90+' => 'Over 90 days late'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $f['age'] === $v ? 'selected' : '' ?>><?= esc($l) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#more-filters" aria-expanded="<?= ($f['date_from'] . $f['date_to'] . $f['min'] . $f['max']) !== '' ? 'true' : 'false' ?>"><i class="bi bi-sliders2 me-1"></i>More filters</button>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-outline-secondary" href="<?= site_url('receivables/export.csv') . '?' . esc($qs(['page' => 0])) ?>"><i class="bi bi-download me-1"></i>Export</a>

        <div class="collapse w-100 <?= ($f['date_from'] . $f['date_to'] . $f['min'] . $f['max']) !== '' ? 'show' : '' ?>" id="more-filters">
            <div class="d-flex flex-wrap gap-2 pt-2">
                <label class="small text-body-secondary d-flex align-items-center gap-1">Invoice date
                    <input class="form-control" type="date" name="date_from" value="<?= esc($f['date_from']) ?>" aria-label="Invoice date from">
                    to <input class="form-control" type="date" name="date_to" value="<?= esc($f['date_to']) ?>" aria-label="Invoice date to"></label>
                <label class="small text-body-secondary d-flex align-items-center gap-1">Amount
                    <input class="form-control inv-minw-7" type="number" step="0.01" min="0" name="min" value="<?= esc($f['min']) ?>" placeholder="Min" aria-label="Minimum amount">
                    to <input class="form-control inv-minw-7" type="number" step="0.01" min="0" name="max" value="<?= esc($f['max']) ?>" placeholder="Max" aria-label="Maximum amount"></label>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table itm-table table-cards">
            <thead><tr>
                <th><?= $sortLink('invoice', 'Invoice #') ?></th>
                <?php if ($multi): ?><th><?= $sortLink('company', 'Company') ?></th><?php endif; ?>
                <th><?= $sortLink('customer', 'Customer') ?></th>
                <th><?= $sortLink('invoice_date', 'Invoice date') ?></th>
                <th><?= $sortLink('due', 'Due date') ?></th>
                <th class="text-end"><?= $sortLink('amount', 'Amount') ?></th>
                <th class="text-end">Still owed</th>
                <th><?= $sortLink('status', 'Status') ?></th>
                <th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($invoices as $i):
                $late     = ar_days_overdue($i);
                $editable = (int) $i['submitted_by'] === $me && in_array($i['status'], ['draft', 'returned', 'rejected'], true);
                $owing    = in_array($i['status'], ['issued', 'part_paid'], true); ?>
                <tr>
                    <td data-label="Invoice #"><a class="fw-semibold text-decoration-none" href="<?= site_url('receivables/' . $i['id']) ?>"><?= esc($i['invoice_number'] ?: 'Draft #' . $i['id']) ?></a>
                        <div class="small text-body-secondary"><?= esc($i['type_name']) ?></div></td>
                    <?php if ($multi): ?><td data-label="Company"><?= esc($i['company_code']) ?></td><?php endif; ?>
                    <td data-label="Customer"><?= esc($i['customer'] ?: '—') ?></td>
                    <td data-label="Invoice date"><?= esc($i['invoice_date'] ?: '—') ?></td>
                    <td data-label="Due date"><?= esc($i['due_date'] ?: '—') ?> <?= invoice_age_badge($late) ?></td>
                    <td data-label="Amount" class="text-end fw-semibold"><?= invoice_money($i['amount'], $i['currency']) ?></td>
                    <td data-label="Still owed" class="text-end"><?= $owing ? invoice_money(\App\Core\Receivables\Collections::outstanding($i), $i['currency']) : '—' ?></td>
                    <td data-label="Status"><?= ar_status_badge($i['status'], $late) ?> <?= invoice_priority_badge($i) ?></td>
                    <td class="actions text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary no-caret" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= esc($i['invoice_number'] ?: 'draft') ?>"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= site_url('receivables/' . $i['id']) ?>">View</a></li>
                                <?php if ($editable): ?><li><a class="dropdown-item" href="<?= site_url('receivables/' . $i['id'] . '/edit') ?>">Edit</a></li><?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invoices === []): ?><tr><td colspan="9" class="text-center text-body-secondary py-4">No customer invoices match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pager">
        <span>Showing <?= (int) $from ?> to <?= (int) $to ?> of <?= (int) $total ?> invoices</span>
        <?php if ($pages > 1):
            // A window of page numbers around the current page, with first and last.
            $shown = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn (int $p): bool => $p >= 1 && $p <= $pages));
            sort($shown); ?>
            <nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">
                <?php $prev = 0; foreach ($shown as $p): ?>
                    <?php if ($prev && $p - $prev > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('receivables') . '?' . esc($qs(['page' => $p])) ?>"><?= $p ?></a></li>
                    <?php $prev = $p; endforeach; ?>
            </ul></nav>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
