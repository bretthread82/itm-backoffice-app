<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <h2 class="h4">Welcome back, <?= esc(current_profile()['first_name'] ?? display_name()) ?></h2>
        <p class="text-body-secondary mb-0">Your back-office tools, in one place.</p>
    </div>
</div>

<div class="row g-3">
    <?php if (isset($stats['active_users'])): ?>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-body-secondary small">Active staff</div>
                <div class="display-6"><?= (int) $stats['active_users'] ?></div>
            </div></div>
        </div>
    <?php endif; ?>

    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Recent notifications</div>
            <ul class="list-group list-group-flush">
                <?php if (! $recent): ?>
                    <li class="list-group-item text-body-secondary">You're all caught up.</li>
                <?php endif; ?>
                <?php foreach ($recent as $n): ?>
                    <li class="list-group-item">
                        <div class="fw-semibold"><?= esc($n['title']) ?></div>
                        <div class="small text-body-secondary"><?= esc($n['created_at']) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
