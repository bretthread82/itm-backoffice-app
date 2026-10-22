<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-12 col-md-8 col-lg-6">
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>These codes are shown <strong>only once</strong>. Save them now (a password manager is ideal). Each code works one time.</div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4">
        <div class="row g-2 text-center">
            <?php foreach ($codes as $c): ?>
                <div class="col-6"><code class="d-block border rounded py-2 fs-6 user-select-all"><?= esc($c) ?></code></div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 text-end"><a class="btn btn-primary" href="<?= site_url('profile/mfa') ?>">I have saved them</a></div>
    </div></div>
</div></div>
<?= $this->endSection() ?>
