<?php
/**
 * Tabs and the company / sub-group filter shared by every receivables report.
 *
 * @var string $reportTab ageing | customers | collections
 * @var array<string, mixed> $f
 * @var list<array<string, mixed>> $companies
 * @var list<array<string, mixed>> $groups
 */
$carry = array_filter(array_intersect_key($f, ['company' => 1, 'group' => 1]), static fn ($v) => $v !== '' && $v !== null && $v !== 0);
$qs    = $carry === [] ? '' : '?' . http_build_query($carry);
$tabs  = [
    'ageing'      => ['receivables/reports', 'Outstanding & ageing', 'bi-bar-chart-line'],
    'customers'   => ['receivables/reports/customers', 'By customer', 'bi-people'],
    'collections' => ['receivables/reports/collections', 'Collections', 'bi-cash-coin'],
];
$here = $tabs[$reportTab][0];
?>
<ul class="nav nav-tabs mb-3" aria-label="Customer invoice reports">
    <?php foreach ($tabs as $key => [$path, $label, $icon]): ?>
        <li class="nav-item"><a class="nav-link <?= $key === $reportTab ? 'active' : '' ?>" <?= $key === $reportTab ? 'aria-current="page"' : '' ?> href="<?= esc(site_url($path) . $qs, 'attr') ?>"><i class="bi <?= $icon ?> me-1"></i><?= esc($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card mb-3">
    <form method="get" class="filter-bar" action="<?= site_url($here) ?>">
        <select class="form-select" name="company" aria-label="Company">
            <option value="">All my companies</option>
            <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (int) ($f['company'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?> · <?= esc($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select" name="group" aria-label="Sub-group">
            <option value="">All sub-groups</option>
            <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>" <?= (int) ($f['group'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option><?php endforeach; ?>
        </select>
        <?php if (! empty($currencies)): ?>
            <select class="form-select" name="currency" aria-label="Currency for the chart">
                <?php foreach ($currencies as $cur): ?><option value="<?= esc($cur) ?>" <?= $cur === ($currency ?? '') ? 'selected' : '' ?>>Chart in <?= esc($cur) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Apply</button>
        <?php if ($f !== []): ?><a class="btn btn-link" href="<?= site_url($here) ?>">Clear</a><?php endif; ?>
    </form>
</div>
