<?php
$fullName = static fn (array $p): string => $p['display_name'] ?: $p['first_name'] . ' ' . $p['last_name'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('directory') ?>"><i class="bi bi-arrow-left me-1"></i>Directory</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body p-4">
                <?= avatar($person, 'avatar-xl mb-3') ?>
                <h2 class="h5 mb-1"><?= esc($fullName($person)) ?></h2>
                <div class="text-body-secondary"><?= esc($person['job_title'] ?? '') ?></div>
                <div class="text-body-secondary small"><?= esc($person['department'] ?? '') ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Contact</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><a href="mailto:<?= esc($person['email'], 'attr') ?>"><?= esc($person['email']) ?></a></dd>
                    <?php if ($person['phone']): ?><dt class="col-sm-3">Phone</dt><dd class="col-sm-9"><?= esc($person['phone']) ?></dd><?php endif; ?>
                    <?php if ($person['mobile']): ?><dt class="col-sm-3">Mobile</dt><dd class="col-sm-9"><?= esc($person['mobile']) ?></dd><?php endif; ?>
                    <?php if ($person['location']): ?><dt class="col-sm-3">Location</dt><dd class="col-sm-9"><?= esc($person['location']) ?></dd><?php endif; ?>
                    <?php if ($manager): ?>
                        <dt class="col-sm-3">Manager</dt>
                        <dd class="col-sm-9"><a href="<?= site_url('directory/' . $manager['user_id']) ?>"><?= esc($fullName($manager)) ?></a></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($person['bio']): ?>
            <div class="card border-0 shadow-sm mb-4"><div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">About</h2>
                <p class="mb-0"><?= nl2br(esc($person['bio'])) ?></p>
            </div></div>
        <?php endif; ?>

        <?php if ($reports): ?>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Direct reports (<?= count($reports) ?>)</h2>
                <div class="row g-2">
                    <?php foreach ($reports as $r): ?>
                        <div class="col-sm-6">
                            <a class="d-flex align-items-center gap-2 text-decoration-none text-body" href="<?= site_url('directory/' . $r['user_id']) ?>">
                                <?= avatar($r) ?>
                                <span><?= esc($fullName($r)) ?><span class="d-block small text-body-secondary"><?= esc($r['job_title'] ?? '') ?></span></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
