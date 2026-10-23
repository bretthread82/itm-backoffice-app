<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Notifications <?= $unread ? '<span class="badge text-bg-danger fs-6">' . (int) $unread . '</span>' : '' ?></h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('notifications') . ($onlyUnread ? '' : '?unread=1') ?>"><?= $onlyUnread ? 'Show all' : 'Only unread' ?></a>
        <a class="btn btn-outline-secondary" href="<?= site_url('account/notifications') ?>"><i class="bi bi-gear me-1"></i>Email settings</a>
        <?php if ($unread): ?>
            <form method="post" action="<?= site_url('notifications/read-all') ?>"><?= csrf_field() ?><button class="btn btn-outline-primary" type="submit">Mark all read</button></form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <ul class="list-group list-group-flush">
        <?php foreach ($items as $n): ?>
            <li class="list-group-item <?= $n['read_at'] === null ? 'bg-primary-subtle' : '' ?>">
                <a class="text-decoration-none d-block" href="<?= site_url('notifications/' . $n['id'] . '/open') ?>">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="<?= $n['read_at'] === null ? 'fw-semibold' : '' ?>"><?= esc($n['title']) ?></span>
                        <span class="small text-body-secondary text-nowrap"><?= esc(time_ago($n['created_at'])) ?></span>
                    </div>
                    <?php if (! empty($n['body'])): ?><div class="small text-body-secondary"><?= esc($n['body']) ?></div><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
        <?php if ($items === []): ?><li class="list-group-item text-center text-body-secondary py-4">You're all caught up.</li><?php endif; ?>
    </ul>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination flex-wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('notifications') . '?' . http_build_query(['page' => $p, 'unread' => $onlyUnread ? 1 : 0]) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
<?= $this->endSection() ?>
