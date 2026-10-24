<?php
/**
 * @var list<array<string, mixed>> $items
 * @var string $empty
 * @var string $mode 'step' shows who the invoice is with, 'status' its status, 'owed' what is still owed (overdue list)
 */
$mode ??= 'status';
?>
<?php if ($items === []): ?>
    <p class="text-body-secondary mb-0"><?= esc($empty) ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table itm-table table-cards">
            <thead><tr>
                <th>Invoice</th><th>Company</th><th>Customer</th><th class="text-end">Amount</th>
                <?php if ($mode === 'owed'): ?><th class="text-end">Still owed</th><th>Due</th><?php else: ?><th><?= $mode === 'step' ? 'With' : 'Status' ?></th><th>Waiting</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td data-label="Invoice"><a class="fw-semibold text-decoration-none" href="<?= site_url('receivables/' . $i['id']) ?>"><?= esc($i['invoice_number'] ?: 'Draft #' . $i['id']) ?></a>
                        <div class="small text-body-secondary"><?= esc($i['type_name']) ?></div></td>
                    <td data-label="Company"><?= esc($i['company_code']) ?></td>
                    <td data-label="Customer"><?= esc($i['customer'] ?: '—') ?></td>
                    <td data-label="Amount" class="text-end"><?= invoice_money($i['amount'], $i['currency']) ?></td>
                    <?php if ($mode === 'owed'): ?>
                        <td data-label="Still owed" class="text-end fw-semibold"><?= invoice_money($i['outstanding'], $i['currency']) ?></td>
                        <td data-label="Due"><?= esc($i['due_date']) ?> <?= invoice_age_badge((int) $i['days_past_due']) ?></td>
                    <?php else: ?>
                        <td data-label="<?= $mode === 'step' ? 'With' : 'Status' ?>"><?= $mode === 'step' ? esc($i['step_label'] ?: '—') : ar_status_badge($i['status']) ?> <?= invoice_priority_badge($i) ?>
                            <?= ($i['acting_as'] ?? null) === 'delegate' ? '<span class="badge text-bg-info">delegated</span>' : '' ?>
                            <?php if (($i['claim_state'] ?? null) === 'available'): ?><span class="badge text-bg-warning">Team: needs accepting</span>
                            <?php elseif (($i['claim_state'] ?? null) === 'mine'): ?><span class="badge text-bg-primary">Accepted by you</span><?php endif; ?></td>
                        <td data-label="Waiting"><?= (int) $i['days_waiting'] ?> d</td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
