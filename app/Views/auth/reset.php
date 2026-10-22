<?php $min = (int) config(\Config\Auth::class)->minimumPasswordLength; ?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<form action="<?= site_url('reset-password') ?>" method="post">
    <?= csrf_field() ?>
    <div class="form-floating mb-1">
        <input type="password" class="form-control" id="password" name="password" placeholder="New password"
               autocomplete="new-password" minlength="<?= $min ?>" required autofocus>
        <label for="password">New password</label>
    </div>
    <div class="form-text mb-3">At least <?= $min ?> characters. Avoid names, your email and common words.</div>
    <div class="form-floating mb-3">
        <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Confirm"
               autocomplete="new-password" required>
        <label for="password_confirm">Confirm new password</label>
    </div>
    <button type="submit" class="btn btn-primary w-100 btn-lg">Change password</button>
</form>
<?= $this->endSection() ?>
