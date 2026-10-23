<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->include('profile/_tabs') ?>

<?php if ($forced): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>You must choose a new password before you can continue.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Change password</h2>
                <?php if ($isEntra): ?>
                    <p class="mb-0 text-body-secondary">Your account signs in with Microsoft. Change your password in your Microsoft account.</p>
                <?php else: ?>
                    <form method="post" action="<?= site_url('profile/security/password') ?>">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="current_password">Current password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">New password</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="<?= (int) config(\Config\Auth::class)->minimumPasswordLength ?>" required>
                            <div class="form-text">At least <?= (int) config(\Config\Auth::class)->minimumPasswordLength ?> characters. Avoid names, emails and common words.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password_confirm">Confirm new password</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
                        </div>
                        <button class="btn btn-primary">Update password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Recent sign-ins</h2>
                <ul class="list-unstyled small mb-0">
                    <?php if (! $logins): ?><li class="text-body-secondary">Nothing recorded yet.</li><?php endif; ?>
                    <?php foreach ($logins as $l): ?>
                        <li class="d-flex justify-content-between py-1">
                            <span>
                                <i class="bi bi-<?= $l['success'] ? 'check-circle text-success' : 'x-circle text-danger' ?> me-1"></i>
                                <?= $l['success'] ? 'Signed in' : 'Failed attempt' ?> · <?= esc($l['ip_address']) ?>
                            </span>
                            <span class="text-body-secondary"><?= esc(local_time($l['date'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">My recent activity</h2>
                <ul class="list-unstyled small mb-0">
                    <?php if (! $activity): ?><li class="text-body-secondary">Nothing recorded yet.</li><?php endif; ?>
                    <?php foreach ($activity as $a): ?>
                        <li class="d-flex justify-content-between py-1">
                            <code><?= esc($a['action']) ?></code>
                            <span class="text-body-secondary"><?= esc(local_time($a['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
