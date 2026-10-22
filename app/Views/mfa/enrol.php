<?php
$labels = ['totp' => ['Authenticator app', 'Use an app such as Microsoft Authenticator, Google Authenticator or Authy.', 'phone', 'mfa/enrol/totp'],
           'email' => ['Email code', 'We email you a one-time code each time you sign in.', 'envelope', 'mfa/enrol/email'],
           'duo'   => ['Duo', 'Approve sign-ins with Duo Mobile or another Duo method.', 'shield-lock', 'mfa/duo/start']];
?>
<?= $this->extend($pending ? 'layouts/auth' : 'layouts/app') ?>

<?= $this->section('content') ?>
<?php if (! $pending): ?><div class="row justify-content-center"><div class="col-12 col-md-8 col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body p-4"><?php endif; ?>

<?php if ($step === 'choose'): ?>
    <?php if ($pending): ?><p class="text-body-secondary small">Your account needs a second step when you sign in. Pick one to set up now.</p><?php endif; ?>
    <?php if (! $options): ?>
        <div class="alert alert-info mb-0">There is nothing left to set up. Ask an administrator if you need a different method.</div>
    <?php endif; ?>
    <div class="list-group">
        <?php foreach ($options as $o): [$name, $desc, $icon, $url] = $labels[$o]; ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-center" href="<?= site_url($url) ?>">
                <i class="bi bi-<?= $icon ?> fs-3 text-primary"></i>
                <span><span class="fw-semibold d-block"><?= esc($name) ?></span><span class="small text-body-secondary"><?= esc($desc) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>

<?php elseif ($step === 'totp'): ?>
    <ol class="small ps-3 text-body-secondary">
        <li>Open your authenticator app and add an account by scanning this code.</li>
        <li>Enter the 6-digit code the app shows to confirm.</li>
    </ol>
    <div class="text-center mb-3">
        <img src="<?= esc($qr, 'attr') ?>" width="220" height="220" alt="QR code to add Back Office to your authenticator app" class="border rounded p-1 bg-white">
        <div class="small text-body-secondary mt-2">Cannot scan? Enter this key manually:</div>
        <code class="user-select-all"><?= esc($secret) ?></code>
    </div>
    <form action="<?= site_url('mfa/enrol/totp') ?>" method="post">
        <?= csrf_field() ?>
        <div class="form-floating mb-3">
            <input class="form-control text-center fs-4" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*"
                   autocomplete="one-time-code" maxlength="7" placeholder="123456" required autofocus>
            <label for="code">6-digit code</label>
        </div>
        <button class="btn btn-primary w-100 btn-lg">Confirm and turn on</button>
    </form>

<?php elseif ($step === 'email'): ?>
    <p class="text-body-secondary small">We will send a code to <strong><?= esc($email) ?></strong>. Enter it to confirm this address works.</p>
    <form action="<?= site_url('mfa/enrol/email') ?>" method="post" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <button class="btn btn-outline-primary w-100"><i class="bi bi-envelope me-1"></i>Email me a code</button>
    </form>
    <form action="<?= site_url('mfa/enrol/email') ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm">
        <div class="form-floating mb-3">
            <input class="form-control text-center fs-4" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*"
                   autocomplete="one-time-code" maxlength="7" placeholder="123456" required>
            <label for="code">6-digit code from the email</label>
        </div>
        <button class="btn btn-primary w-100 btn-lg">Confirm and turn on</button>
    </form>
<?php endif; ?>

<?php if ($pending): ?>
    <form action="<?= site_url('mfa/cancel') ?>" method="post" class="text-center mt-3">
        <?= csrf_field() ?><button class="btn btn-link btn-sm text-body-secondary">Cancel and sign in again</button>
    </form>
<?php else: ?>
    <div class="mt-3"><a href="<?= site_url('profile/mfa') ?>">&larr; Back to two-step settings</a></div>
<?php endif; ?>

<?php if (! $pending): ?></div></div></div></div><?php endif; ?>
<?= $this->endSection() ?>
