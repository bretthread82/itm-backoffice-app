<?php
/**
 * @var array<string, mixed> $tab waiting, mine, overdue, summary, dueSoon, received, subscriptions, canSubmit, activity
 */
$money = static fn (array $amounts): string => \App\Core\Payables\ReportService::formatAmounts($amounts);
$mine     = $tab['mine'];
$returned = count($mine['needs_action']);
$drafts   = count($mine['drafts']);
$waiting  = count($tab['waiting']);
$overdue  = (int) $tab['summary']['overdue']['count'];
$dueSoon  = (int) $tab['dueSoon']['count'];
$stat = static fn (string $label, int $n, string $sub, string $icon, string $tone, string $href): array => [
    'statLabel' => $label, 'statValue' => (string) $n, 'statSub' => esc($sub), 'statIcon' => $icon, 'statTone' => $n > 0 ? $tone : 'success', 'statHref' => $href,
];
?>
<div class="d-flex justify-content-end gap-2 mb-3">
    <?php if ($tab['canSubmit']): ?><a class="btn btn-primary" href="<?= site_url('receivables/new') ?>"><i class="bi bi-plus-lg me-1"></i>New customer invoice</a><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= site_url('receivables') ?>">Customer invoices</a>
</div>

<div class="row g-3 mb-2">
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Waiting on me', $waiting, 'Approvals and issuing for you or your team', 'bi-check2-square', 'warning', site_url('receivables/tasks')))->include('payables/components/stat_card') ?></div>
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Sent back to me', $returned, 'Returned or rejected', 'bi-arrow-return-left', 'danger', site_url('receivables/tasks')))->include('payables/components/stat_card') ?></div>
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Overdue', $overdue, $money($tab['summary']['overdue']['amount']), 'bi-exclamation-triangle', 'danger', site_url('receivables?status=overdue')))->include('payables/components/stat_card') ?></div>
    <div class="col-12 col-md-6 col-xl-3"><?= $this->setData($stat('Due in 7 days', $dueSoon, $money($tab['dueSoon']['amount']), 'bi-calendar-event', 'info', site_url('receivables?status=collecting')))->include('payables/components/stat_card') ?></div>
</div>
<p class="small text-body-secondary mb-4">Still to collect: <strong><?= esc($money($tab['summary']['outstanding']['amount'])) ?></strong>.
    Received in the last 7 days, after reversals: <strong><?= esc($money($tab['received']['amount'])) ?></strong>.
    <a class="text-decoration-none" href="<?= site_url('receivables/subscriptions') ?>">Subscriptions</a> billing in the next 14 days:
    <strong><?= (int) $tab['subscriptions']['count'] ?></strong><?= $tab['subscriptions']['count'] > 0 ? ' (' . esc($money($tab['subscriptions']['amount'])) . ')' : '' ?>.</p>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span>Waiting on me</span><a class="small text-decoration-none" href="<?= site_url('receivables/tasks') ?>">Open My tasks</a>
    </div>
    <div class="card-body">
        <?= view('receivables/tasks/_list', ['items' => array_slice($tab['waiting'], 0, 8), 'empty' => 'Nothing waiting on you.', 'mode' => 'step']) ?>
    </div>
</div>

<?php if ($tab['overdue'] !== []): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
            <span>Overdue to chase (<?= count($tab['overdue']) ?>)</span><a class="small text-decoration-none" href="<?= site_url('receivables?status=overdue') ?>">See all overdue</a>
        </div>
        <div class="card-body"><?= view('receivables/tasks/_list', ['items' => array_slice($tab['overdue'], 0, 8), 'empty' => '', 'mode' => 'owed']) ?></div>
    </div>
<?php endif; ?>

<?php if ($returned > 0 || $drafts > 0): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">My invoices that need action</div>
        <div class="card-body">
            <?php if ($returned > 0): ?>
                <h3 class="h6">Returned or rejected (<?= $returned ?>)</h3>
                <?= view('receivables/tasks/_list', ['items' => $mine['needs_action'], 'empty' => '', 'mode' => 'status']) ?>
            <?php endif; ?>
            <?php if ($drafts > 0): ?>
                <h3 class="h6 <?= $returned > 0 ? 'mt-4' : '' ?>">Drafts to finish (<?= $drafts ?>)</h3>
                <?= view('receivables/tasks/_list', ['items' => $mine['drafts'], 'empty' => '', 'mode' => 'status']) ?>
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
                <a class="text-decoration-none" href="<?= site_url('receivables/' . $a['invoice_id']) ?>"><?= esc(lcfirst($a['text'])) ?></a>
                <?php if ($a['override']): ?><span class="badge text-bg-secondary">group override</span><?php endif; ?>
                <div class="small text-body-secondary"><?= esc($a['at']) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
