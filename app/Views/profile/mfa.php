<?php
$meta = [
    'totp'  => ['Authenticator app', 'Codes from an app on your phone.', 'phone', 'mfa/enrol/totp'],
    'email' => ['Email code', 'A one-time code sent to your email address.', 'envelope', 'mfa/enrol/email'],
    'duo'   => ['Duo', 'Approve sign-ins with Duo.', 'shield-lock', 'mfa/duo/start'],
];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->include('profile/_tabs') ?>

<?php if ($isEntra): ?>
    <div class="alert alert-info"><i class="bi bi-microsoft me-1"></i>You sign in with Microsoft, so two-step verification is handled by your Microsoft account.</div>
<?php elseif (! $mfaEnabled): ?>
    <div class="alert alert-secondary">Two-step verification is currently turned off by your administrator. Anything you set up is kept and applies if it is turned on.</div>
<?php elseif ($required): ?>
    <div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1"></i>Your role requires two-step verification.</div>
<?php endif; ?>

<?php if (! $isEntra): ?>
<?php if (! $reauth): ?>
    <form method="post" action="<?= site_url('profile/mfa/confirm') ?>" class="card border-0 shadow-sm mb-4">
        <?= csrf_field() ?>
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="current_password">Confirm your password to change these settings</label>
                <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="col-md-4"><button class="btn btn-primary w-100">Confirm</button></div>
        </div>
    </form>
<?php else: ?>
    <div class="small text-body-secondary mb-2"><i class="bi bi-unlock me-1"></i>Password confirmed. You can change settings for the next <?= (int) $reauthMin ?> minutes.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <ul class="list-group list-group-flush">
        <?php foreach ($meta as $type => [$name, $desc, $icon, $url]): $r = $rows[$type]; ?>
            <li class="list-group-item d-flex flex-wrap gap-3 align-items-center py-3">
                <i class="bi bi-<?= $icon ?> fs-3 text-primary"></i>
                <div class="flex-fill">
                    <div class="fw-semibold"><?= esc($name) ?>
                        <?php if ($r['enrolled']): ?><span class="badge text-bg-success ms-1">On</span><?php endif; ?>
                        <?php if (! $r['switchedOn']): ?><span class="badge text-bg-secondary ms-1">Not offered</span><?php endif; ?>
                    </div>
                    <div class="small text-body-secondary"><?= esc($desc) ?><?= $r['last_used'] ? ' Last used ' . esc(date('j M Y', strtotime($r['last_used']))) . '.' : '' ?></div>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($r['enrolled'] && $reauth): ?>
                        <form method="post" action="<?= site_url('profile/mfa/disable/' . $type) ?>" data-confirm="Remove this two-step method?">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    <?php endif; ?>
                    <?php if (! $r['enrolled'] && $r['switchedOn'] && $reauth): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= site_url($url) ?>">Set up</a>
                    <?php endif; ?>
                    <?php if ($type === 'totp' && $r['enrolled'] && $r['switchedOn'] && $reauth): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url($url) ?>">Replace</a>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h2 class="h6 text-uppercase text-body-secondary">Recovery codes</h2>
            <p class="small text-body-secondary">One-time codes for when you cannot use your usual method. Keep them somewhere safe.</p>
            <p class="mb-3"><strong><?= (int) $recovery ?></strong> unused code<?= (int) $recovery === 1 ? '' : 's' ?> left.</p>
            <?php if ($reauth): ?>
                <form method="post" action="<?= site_url('profile/mfa/recovery') ?>" data-confirm="Generate new codes? Your old codes will stop working.">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Generate new codes</button>
                </form>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h2 class="h6 text-uppercase text-body-secondary">Remembered devices</h2>
            <p class="small text-body-secondary">Browsers where you chose not to be asked again.</p>
            <p class="mb-3"><strong><?= (int) $devices ?></strong> device<?= (int) $devices === 1 ? '' : 's' ?>.</p>
            <?php if ($devices): ?>
                <form method="post" action="<?= site_url('profile/mfa/devices/forget') ?>" data-confirm="Ask for a code again on every device?">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-warning">Forget all devices</button>
                </form>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>
