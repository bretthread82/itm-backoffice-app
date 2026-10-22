<?php
$labels = ['totp' => ['Authenticator app', 'phone'], 'email' => ['Email code', 'envelope'], 'duo' => ['Duo', 'shield-lock'], 'recovery' => ['Recovery code', 'life-preserver']];
$tabs   = $usable;
if ($hasRecovery) { $tabs[] = 'recovery'; }
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php if (count($tabs) > 1): ?>
    <ul class="nav nav-pills nav-fill mb-3 small">
        <?php foreach ($tabs as $t): ?>
            <li class="nav-item">
                <a class="nav-link <?= $method === $t ? 'active' : '' ?>" href="<?= site_url('mfa/challenge?method=' . $t) ?>">
                    <i class="bi bi-<?= $labels[$t][1] ?> me-1"></i><?= esc($labels[$t][0]) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($method === 'totp'): ?>
    <p class="text-body-secondary small">Open your authenticator app and enter the 6-digit code for Back Office.</p>
    <form action="<?= site_url('mfa/challenge') ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="method" value="totp">
        <div class="form-floating mb-3">
            <input class="form-control text-center fs-4" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*"
                   autocomplete="one-time-code" maxlength="7" placeholder="123456" required autofocus>
            <label for="code">6-digit code</label>
        </div>
        <?= $this->include('mfa/_remember') ?>
        <button class="btn btn-primary w-100 btn-lg">Verify</button>
    </form>

<?php elseif ($method === 'email'): ?>
    <p class="text-body-secondary small">We can email a one-time code to <strong><?= esc($email) ?></strong>.</p>
    <form action="<?= site_url('mfa/email/send') ?>" method="post" class="mb-3">
        <?= csrf_field() ?>
        <button class="btn btn-outline-primary w-100"><i class="bi bi-envelope me-1"></i>Email me a code</button>
    </form>
    <form action="<?= site_url('mfa/challenge') ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="method" value="email">
        <div class="form-floating mb-3">
            <input class="form-control text-center fs-4" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*"
                   autocomplete="one-time-code" maxlength="7" placeholder="123456" required>
            <label for="code">6-digit code from the email</label>
        </div>
        <?= $this->include('mfa/_remember') ?>
        <button class="btn btn-primary w-100 btn-lg">Verify</button>
    </form>

<?php elseif ($method === 'duo'): ?>
    <p class="text-body-secondary small">You will be sent to Duo to approve this sign-in, then brought back here.</p>
    <form action="<?= site_url('mfa/duo/start') ?>" method="get">
        <?= $this->include('mfa/_remember') ?>
        <button class="btn btn-primary w-100 btn-lg"><i class="bi bi-shield-lock me-1"></i>Continue with Duo</button>
    </form>

<?php else: ?>
    <p class="text-body-secondary small">Enter one of the recovery codes you saved when you set up two-step verification. Each works once.</p>
    <form action="<?= site_url('mfa/challenge') ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="method" value="recovery">
        <div class="form-floating mb-3">
            <input class="form-control text-center fs-5" id="code" name="code" autocomplete="off" autocapitalize="characters"
                   maxlength="12" placeholder="ABCDE-FGHJK" required autofocus>
            <label for="code">Recovery code</label>
        </div>
        <button class="btn btn-primary w-100 btn-lg">Use recovery code</button>
    </form>
<?php endif; ?>

<form action="<?= site_url('mfa/cancel') ?>" method="post" class="text-center mt-3">
    <?= csrf_field() ?>
    <button class="btn btn-link btn-sm text-body-secondary">Cancel and sign in again</button>
</form>
<?= $this->endSection() ?>
