<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<div class="d-flex gap-2">
    <div class="btn-group" role="group" aria-label="Filter">
        <a class="btn btn-outline-secondary <?= $unread ? '' : 'active' ?>" href="<?= site_url('notifications') ?>">All</a>
        <a class="btn btn-outline-secondary <?= $unread ? 'active' : '' ?>" href="<?= site_url('notifications?filter=unread') ?>">Unread</a>
    </div>
    <form method="post" action="<?= site_url('notifications/read-all') ?>">
        <?= csrf_field() ?>
        <button class="btn btn-outline-primary"><i class="bi bi-check2-all me-1"></i>Mark all read</button>
    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
    <?php if (! $rows): ?>
        <div class="card-body text-center text-body-secondary py-5">
            <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
            <?= $unread ? 'No unread notifications.' : 'You have no notifications yet.' ?>
        </div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($rows as $n): ?>
                <li class="list-group-item p-0">
                    <a class="d-flex gap-3 align-items-start p-3 text-decoration-none text-body notif-row <?= $n['read_at'] ? '' : 'notif-unread' ?>"
                       href="<?= site_url('notifications/' . $n['id'] . '/open') ?>">
                        <span class="notif-dot mt-2" aria-hidden="true"></span>
                        <span class="flex-fill">
                            <span class="d-block <?= $n['read_at'] ? '' : 'fw-semibold' ?>"><?= esc($n['title']) ?></span>
                            <?php if ($n['body']): ?><span class="d-block small text-body-secondary"><?= esc($n['body']) ?></span><?php endif; ?>
                        </span>
                        <span class="small text-body-secondary text-nowrap"><?= esc(date('j M, H:i', strtotime($n['created_at']))) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="card-footer bg-transparent"><?= $pager ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
