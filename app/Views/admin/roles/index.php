<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-primary" href="<?= site_url('admin/roles/new') ?>"><i class="bi bi-plus-lg me-1"></i>New role</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <?php foreach ($roles as $r): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h2 class="h5 mb-1"><?= esc($r['name']) ?></h2>
                        <?php if ($r['is_system']): ?><span class="badge text-bg-light border">System</span><?php endif; ?>
                    </div>
                    <p class="text-body-secondary small"><?= esc($r['description'] ?? '') ?></p>
                    <div class="d-flex gap-3 small text-body-secondary">
                        <span><i class="bi bi-people me-1"></i><?= (int) $r['members'] ?> member<?= (int) $r['members'] === 1 ? '' : 's' ?></span>
                        <span><i class="bi bi-key me-1"></i><?= (int) $r['permission_count'] ?> permission<?= (int) $r['permission_count'] === 1 ? '' : 's' ?></span>
                    </div>
                </div>
                <div class="card-footer bg-transparent d-flex gap-2">
                    <a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/roles/' . $r['id'] . '/edit') ?>">Edit</a>
                    <?php if (! $r['is_system']): ?>
                        <form method="post" action="<?= site_url('admin/roles/' . $r['id'] . '/delete') ?>"
                              data-confirm="Delete this role? Members will lose its permissions.">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
