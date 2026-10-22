<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php if (! $available): ?>
    <div class="alert alert-info mb-3">
        Resetting a password by email is not set up on this system. Please ask an administrator to reset your password.
    </div>
<?php else: ?>
    <form action="<?= site_url('forgot-password') ?>" method="post">
        <?= csrf_field() ?>
        <p class="text-body-secondary small">Enter the email address you sign in with. If it belongs to an account, we will send a link to choose a new password.</p>
        <div class="form-floating mb-3">
            <input type="email" class="form-control" id="email" name="email" placeholder="Email" inputmode="email"
                   autocomplete="email" maxlength="254" required autofocus>
            <label for="email">Email address</label>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg">Send reset link</button>
    </form>
<?php endif; ?>
<div class="text-center mt-3"><a href="<?= url_to('login') ?>">Back to sign in</a></div>
<?= $this->endSection() ?>
