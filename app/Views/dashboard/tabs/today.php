<?php
/**
 * @var array<string, array<string, mixed>> $tabs
 * @var array<string, int> $badges
 * @var array<string, mixed> $tab
 */
?>
<div class="row g-3 mb-4">
    <?php foreach ($tabs as $key => $t): ?>
        <?php $n = (int) ($badges[$key] ?? 0); ?>
        <div class="col-12 col-md-6 col-xl-4">
            <?= $this->setData([
                'statLabel' => $t['label'],
                'statValue' => $n > 0 ? (string) $n : 'Clear',
                'statSub'   => $n > 0 ? 'Needs your attention' : 'Nothing waiting on you',
                'statIcon'  => 'bi-' . $t['icon'],
                'statTone'  => $n > 0 ? 'warning' : 'success',
                'statHref'  => site_url('/?tab=' . $key),
            ])->include('invoices/components/stat_card') ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span>Unread notifications <?php if ($tab['unreadTotal'] > 0): ?><span class="badge text-bg-secondary"><?= (int) $tab['unreadTotal'] ?></span><?php endif; ?></span>
        <a class="small text-decoration-none" href="<?= site_url('notifications') ?>">See all</a>
    </div>
    <ul class="list-group list-group-flush">
        <?php if (! $tab['unread']): ?>
            <li class="list-group-item text-body-secondary">You're all caught up.</li>
        <?php endif; ?>
        <?php foreach ($tab['unread'] as $n): ?>
            <li class="list-group-item">
                <?php if ($n['url']): ?><a class="fw-semibold text-decoration-none" href="<?= site_url($n['url']) ?>"><?= esc($n['title']) ?></a>
                <?php else: ?><span class="fw-semibold"><?= esc($n['title']) ?></span><?php endif; ?>
                <div class="small text-body-secondary"><?= esc(local_time($n['created_at'], 'Y-m-d H:i')) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
