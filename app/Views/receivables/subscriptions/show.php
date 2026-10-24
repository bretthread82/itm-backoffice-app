<?php
/** @var array<string, mixed> $sub */
$tone = ['active' => 'success', 'paused' => 'warning', 'ended' => 'draft'];
$days = (int) round((strtotime((string) $sub['next_bill_date']) - strtotime($today)) / 86400);
$row  = static fn (string $label, string $value): string => '<dt class="col-sm-4 text-body-secondary fw-normal">' . esc($label) . '</dt><dd class="col-sm-8">' . $value . '</dd>';
$subId = (int) $sub['id'];
$act   = static fn (string $action, string $label, string $class, string $confirm = ''): string => '<form method="post" class="d-inline" action="' . site_url('receivables/subscriptions/' . $subId . '/' . $action) . '"'
    . ($confirm !== '' ? ' data-confirm="' . esc($confirm, 'attr') . '"' : '') . '>' . csrf_field() . '<button class="btn ' . $class . '" type="submit">' . esc($label) . '</button></form>';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData([
    'headerTitle'    => $sub['customer'],
    'headerSubtitle' => $sub['description'] . ' · ' . $sub['company_code'],
    'headerActions'  => '<a class="btn btn-outline-secondary" href="' . site_url('receivables/subscriptions') . '"><i class="bi bi-arrow-left me-1"></i>All subscriptions</a>',
])->include('payables/components/page_header') ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <h2 class="h6 text-uppercase text-body-secondary mb-0">Details</h2>
                <span class="badge-status st-<?= $tone[$sub['status']] ?? 'draft' ?>"><?= esc(ucfirst($sub['status'])) ?></span>
            </div>
            <dl class="row mb-0">
                <?= $row('Customer', esc($sub['customer'])) ?>
                <?= $row('Service', esc($sub['description'])) ?>
                <?= $row('Invoice type', esc($sub['type_name'])) ?>
                <?= $row('Amount', invoice_money($sub['amount'], $sub['currency']) . ' ' . esc(strtolower(\App\Core\Receivables\SubscriptionService::FREQUENCIES[$sub['frequency']] ?? '')) ) ?>
                <?= $row('Next bill date', $sub['status'] === 'ended' ? '—' : esc($sub['next_bill_date']) . ($sub['status'] === 'active' ? ' <span class="text-body-secondary">(' . ($days <= 0 ? 'due now' : ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days')) . ')</span>' : '')) ?>
                <?= $row('Started', esc($sub['start_date'])) ?>
                <?= $row('Ends on', $sub['end_date'] ? esc($sub['end_date']) : 'No end date') ?>
                <?= $row('Payment terms', (int) $sub['payment_terms_days'] . ' days') ?>
                <?php if (! empty($sub['notes'])): ?><?= $row('Notes', nl2br(esc($sub['notes']))) ?><?php endif; ?>
            </dl>
            <?php if ($canManage && $sub['status'] !== 'ended'): ?>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a class="btn btn-outline-secondary" href="<?= site_url('receivables/subscriptions/' . $sub['id'] . '/edit') ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                    <?= $sub['status'] === 'active'
                        ? $act('pause', 'Pause', 'btn-outline-secondary', 'Pause this subscription? No invoices are raised while it is paused.')
                        : $act('resume', 'Resume', 'btn-primary') ?>
                    <?= $act('end', 'End subscription', 'btn-outline-danger', 'End this subscription? It cannot be started again, and no more invoices will be raised.') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card card-body">
            <h2 class="h6 text-uppercase text-body-secondary mb-2">Who is told</h2>
            <p class="small mb-0">
                <?= esc(implode(', ', array_map(static fn (int $d): string => (string) $d, $leadDays))) ?> day(s) before each bill date, the person who set it up and everyone who can
                record payments for <?= esc($sub['company_code']) ?> get a notice. When a draft invoice is created they are told again.
            </p>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header bg-transparent fw-semibold">Invoices raised</div>
            <div class="table-responsive">
                <table class="table itm-table table-cards mb-0">
                    <thead><tr><th>Bill date</th><th>Invoice</th><th>Due date</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $i): ?>
                        <tr>
                            <td data-label="Bill date"><?= esc($i['bill_date']) ?></td>
                            <td data-label="Invoice"><a class="text-decoration-none" href="<?= site_url('receivables/' . $i['id']) ?>"><?= esc($i['invoice_number'] ?: 'Draft #' . $i['id']) ?></a></td>
                            <td data-label="Due date"><?= esc($i['due_date'] ?: '—') ?></td>
                            <td data-label="Amount" class="text-end"><?= invoice_money($i['amount'], $i['currency']) ?></td>
                            <td data-label="Status"><?= ar_status_badge((string) $i['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($invoices === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">No invoices raised yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
