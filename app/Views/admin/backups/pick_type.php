<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/backups') ?>">Cancel</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="text-body-secondary">Where should backups be sent? You can add more than one.</p>
<div class="row g-3">
    <?php foreach ($types as $key => $t): ?>
        <div class="col-12 col-md-6">
            <a class="card border-0 shadow-sm h-100 text-decoration-none" href="<?= site_url('admin/backups/destinations/new?type=' . $key) ?>">
                <div class="card-body">
                    <div class="h6 mb-1"><i class="bi bi-<?= esc($t['icon']) ?> me-2"></i><?= esc($t['label']) ?></div>
                    <div class="small text-body-secondary"><?= esc($t['summary']) ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
