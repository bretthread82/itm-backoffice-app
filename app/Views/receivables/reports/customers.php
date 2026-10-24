<?php
/**
 * @var list<array<string, mixed>> $customers ArReportService::outstandingByCustomer()
 * @var array<string, mixed> $f
 */
use App\Core\Payables\ReportService;

$money = static fn (array $a): string => esc(ReportService::formatAmounts($a));
$link  = array_filter(['company' => $f['company'] ?? 0]);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => 'Outstanding by customer',
    'headerSubtitle' => 'Who owes what, and who is furthest behind',
    'headerActions'  => '<a class="btn btn-outline-primary" href="' . site_url('receivables/reports/customers.csv') . '?' . esc(http_build_query($f)) . '"><i class="bi bi-filetype-csv me-1"></i>Download (CSV)</a>',
])->include('payables/components/page_header') ?>
<?= $this->setData(['reportTab' => 'customers'])->include('receivables/reports/_tabs') ?>

<p class="small text-body-secondary mb-3">
    Customers with issued or part-paid invoices still owing money, the most behind first. The balance is what is still owed after payments.
    Amounts are shown per currency and never added across currencies. An invoice raised for a customer that is not on the customer list is grouped by the name typed on it.
</p>

<div class="card">
    <?php if ($customers === []): ?>
        <div class="card-body text-body-secondary">No customer owes anything.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table itm-table table-cards">
                <thead><tr><th>Customer</th><th class="text-end">Invoices</th><th class="text-end">Still owed</th><th class="text-end">Of which overdue</th><th class="text-end">Critical</th><th class="text-end">Oldest</th></tr></thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr class="<?= $c['critical'] > 0 ? 'table-danger' : '' ?>">
                        <td data-label="Customer">
                            <a class="fw-semibold text-decoration-none" href="<?= site_url('receivables') . '?' . http_build_query($link + ['status' => 'collecting', 'q' => $c['customer']]) ?>"><?= esc($c['customer']) ?></a>
                            <?php if ($c['code'] !== ''): ?><div class="small text-body-secondary"><?= esc($c['code']) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Invoices" class="text-end"><?= (int) $c['count'] ?></td>
                        <td data-label="Still owed" class="text-end fw-semibold"><?= $money($c['amount']) ?></td>
                        <td data-label="Of which overdue" class="text-end"><?= $c['overdue'] > 0 ? $money($c['overdue_amount']) . ' <span class="small text-body-secondary">(' . (int) $c['overdue'] . ')</span>' : '—' ?></td>
                        <td data-label="Critical" class="text-end"><?= $c['critical'] > 0 ? '<span class="badge text-bg-danger">' . (int) $c['critical'] . '</span>' : '0' ?></td>
                        <td data-label="Oldest" class="text-end"><?= $c['worst'] > 0 ? (int) $c['worst'] . ' d' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
