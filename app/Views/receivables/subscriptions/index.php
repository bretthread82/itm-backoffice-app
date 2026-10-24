<?php
$multi = count($companies) > 1;
$tone  = ['active' => 'success', 'paused' => 'warning', 'ended' => 'draft'];
$qs    = static fn (array $over): string => http_build_query(array_filter($over + $f, static fn ($v) => $v !== '' && $v !== null && $v !== 0));
$from  = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to    = min($total, $page * $perPage);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Customer subscriptions',
    'headerSubtitle' => 'Recurring services we bill customers for. A draft invoice is raised each cycle and the people looking after it are told ahead of time.',
    'headerActions'  => ($canManage ? '<a class="btn btn-primary" href="' . site_url('receivables/subscriptions/new') . '"><i class="bi bi-plus-lg me-1"></i>New subscription</a>' : ''),
])->include('payables/components/page_header') ?>

<div class="card">
    <form method="get" class="filter-bar" action="<?= site_url('receivables/subscriptions') ?>">
        <div class="grow"><input class="form-control w-100" type="search" name="q" value="<?= esc($f['q']) ?>" placeholder="Customer or service" aria-label="Search"></div>
        <select class="form-select" name="status" aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $f['status'] === $v ? 'selected' : '' ?>><?= esc($l) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($multi): ?>
            <select class="form-select" name="company" aria-label="Company">
                <option value="">All companies</option>
                <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $f['company'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>

    <div class="table-responsive">
        <table class="table itm-table table-cards">
            <thead><tr>
                <th>Customer</th>
                <?php if ($multi): ?><th>Company</th><?php endif; ?>
                <th>Service</th>
                <th>Billed</th>
                <th class="text-end">Amount</th>
                <th>Next bill date</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $s):
                $days = (int) round((strtotime((string) $s['next_bill_date']) - strtotime($today)) / 86400); ?>
                <tr>
                    <td data-label="Customer"><a class="fw-semibold text-decoration-none" href="<?= site_url('receivables/subscriptions/' . $s['id']) ?>"><?= esc($s['customer']) ?></a></td>
                    <?php if ($multi): ?><td data-label="Company"><?= esc($s['company_code']) ?></td><?php endif; ?>
                    <td data-label="Service"><?= esc($s['description']) ?><div class="small text-body-secondary"><?= esc($s['type_name']) ?></div></td>
                    <td data-label="Billed"><?= esc(\App\Core\Receivables\SubscriptionService::FREQUENCIES[$s['frequency']] ?? $s['frequency']) ?></td>
                    <td data-label="Amount" class="text-end fw-semibold"><?= invoice_money($s['amount'], $s['currency']) ?></td>
                    <td data-label="Next bill date"><?php if ($s['status'] === 'ended'): ?>—<?php else: ?>
                        <?= esc($s['next_bill_date']) ?>
                        <?php if ($s['status'] === 'active'): ?><span class="small text-body-secondary d-block"><?= $days <= 0 ? 'due now' : ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') ?></span><?php endif; ?>
                    <?php endif; ?></td>
                    <td data-label="Status"><span class="badge-status st-<?= $tone[$s['status']] ?? 'draft' ?>"><?= esc(ucfirst($s['status'])) ?></span></td>
                    <td class="actions text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('receivables/subscriptions/' . $s['id']) ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">No subscriptions match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pager">
        <span>Showing <?= (int) $from ?> to <?= (int) $to ?> of <?= (int) $total ?> subscriptions</span>
        <?php if ($pages > 1): ?>
            <nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('receivables/subscriptions') . '?' . esc($qs(['page' => $p])) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        <?php endif; ?>
    </div>
</div>
<p class="small text-body-secondary mt-3">People are told <?= esc(implode(', ', array_map(static fn (int $d): string => (string) $d, $leadDays))) ?> day(s) before a bill date: the person who set it up and everyone who can record payments for the company.</p>
<?= $this->endSection() ?>
