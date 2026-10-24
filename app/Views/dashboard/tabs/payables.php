<?php
/**
 * @var array<string, mixed> $tab waiting, mine, agg, dueSoon, canSubmit, activity
 */
$money = static function (array $amounts): string {
    if ($amounts === []) {
        return '—';
    }
    $parts = [];
    foreach ($amounts as $cur => $sum) {
        $parts[] = esc($cur) . ' ' . number_format((float) $sum, 2);
    }

    return implode(' · ', $parts);
};
$mine      = $tab['mine'];
$returned  = count($mine['needs_action']);
$drafts    = count($mine['drafts']);
$overdue   = (int) $tab['agg']['overdue']['count'];
$dueSoon   = (int) $tab['dueSoon']['count'];
$waiting   = count($tab['waiting']);
$stat = static fn (string $label, int $n, string $sub, string $icon, string $tone, string $href): array => [
    'statLabel' => $label, 'statValue' => (string) $n, 'statSub' => $sub, 'statIcon' => $icon, 'statTone' => $n > 0 ? $tone : 'success', 'statHref' => $href,
];
?>
<div class="d-flex justify-content-end gap-2 mb-3">
    <?php if ($tab['canSubmit']): ?><a class="btn btn-primary" href="<?= site_url('payables/new') ?>"><i class="bi bi-plus-lg me-1"></i>New invoice</a><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= site_url('payables/overview') ?>">Invoice overview</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Waiting on me', $waiting, 'Approvals for you or your team', 'bi-check2-square', 'warning', site_url('approvals')))->include('payables/components/stat_card') ?></div>
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Sent back to me', $returned, 'Returned or rejected', 'bi-arrow-return-left', 'danger', site_url('approvals')))->include('payables/components/stat_card') ?></div>
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Overdue', $overdue, $money($tab['agg']['overdue']['amount']), 'bi-exclamation-triangle', 'danger', site_url('payables')))->include('payables/components/stat_card') ?></div>
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Due in 7 days', $dueSoon, $money($tab['dueSoon']['amount']), 'bi-calendar-event', 'info', site_url('payables')))->include('payables/components/stat_card') ?></div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span>Waiting on me</span><a class="small text-decoration-none" href="<?= site_url('approvals') ?>">Open My tasks</a>
    </div>
    <div class="card-body">
        <?= view('payables/approvals/_list', ['items' => array_slice($tab['waiting'], 0, 8), 'empty' => 'Nothing waiting on you.', 'showStep' => true]) ?>
    </div>
</div>

<?php if ($returned > 0 || $drafts > 0): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">My invoices that need action</div>
        <div class="card-body">
            <?php if ($returned > 0): ?>
                <h3 class="h6">Returned or rejected (<?= $returned ?>)</h3>
                <?= view('payables/approvals/_list', ['items' => $mine['needs_action'], 'empty' => '', 'showStep' => false]) ?>
            <?php endif; ?>
            <?php if ($drafts > 0): ?>
                <h3 class="h6 <?= $returned > 0 ? 'mt-4' : '' ?>">Drafts to finish (<?= $drafts ?>)</h3>
                <?= view('payables/approvals/_list', ['items' => $mine['drafts'], 'empty' => '', 'showStep' => false]) ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold">Recent activity</div>
    <ul class="list-group list-group-flush">
        <?php if (! $tab['activity']): ?><li class="list-group-item text-body-secondary">No recent activity.</li><?php endif; ?>
        <?php foreach ($tab['activity'] as $a): ?>
            <li class="list-group-item">
                <span class="fw-semibold"><?= esc($a['actor']) ?></span>
                <a class="text-decoration-none" href="<?= site_url('payables/' . $a['invoice_id']) ?>"><?= esc(lcfirst($a['text'])) ?></a>
                <?php if ($a['override']): ?><span class="badge text-bg-secondary">group override</span><?php endif; ?>
                <div class="small text-body-secondary"><?= esc($a['at']) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
