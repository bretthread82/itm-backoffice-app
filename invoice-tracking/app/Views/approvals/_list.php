<?php
/**
 * @var list<array<string, mixed>> $items
 * @var string $empty
 * @var bool $showStep
 */
?>
<?php if ($items === []): ?>
    <p class="text-body-secondary mb-0"><?= esc($empty) ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table mg-table table-cards">
            <thead><tr><th>Invoice</th><th>Company</th><th>Supplier</th><th class="text-end">Amount</th><th><?= $showStep ? 'With' : 'Status' ?></th><th>Waiting</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td data-label="Invoice"><a class="fw-semibold text-decoration-none" href="<?= site_url('invoices/' . $i['id']) ?>"><?= esc($i['invoice_number'] ?: 'Draft #' . $i['id']) ?></a>
                        <div class="small text-body-secondary"><?= esc($i['type_name']) ?></div></td>
                    <td data-label="Company"><?= esc($i['company_code']) ?></td>
                    <td data-label="Supplier"><?= esc($i['supplier'] ?: '—') ?></td>
                    <td data-label="Amount" class="text-end"><?= invoice_money($i['amount'], $i['currency']) ?></td>
                    <td data-label="<?= $showStep ? 'With' : 'Status' ?>"><?= $showStep ? esc($i['step_label'] ?: '—') : invoice_status_badge($i['status']) ?> <?= invoice_priority_badge($i) ?>
                        <?= ($i['acting_as'] ?? null) === 'delegate' ? '<span class="badge text-bg-info">delegated</span>' : '' ?>
                        <?php if (($i['claim_state'] ?? null) === 'available'): ?><span class="badge text-bg-warning">Team: needs accepting</span>
                        <?php elseif (($i['claim_state'] ?? null) === 'mine'): ?><span class="badge text-bg-primary">Accepted by you</span><?php endif; ?></td>
                    <td data-label="Waiting"><?= (int) $i['days_waiting'] ?> d <?= invoice_age_badge((int) $i['days_past_due']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
