<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
        <h1 class="h3 mb-3">Change password</h1>
        <?php if ($forced): ?>
            <div class="alert alert-info">Please choose a new password before you continue.</div>
        <?php endif; ?>
        <form method="post" action="<?= site_url('account/password') ?>" class="card card-body" autocomplete="off">
            <?= csrf_field() ?>
            <?php if (! $forced): ?>
                <div class="mb-3">
                    <label class="form-label" for="current_password">Current password</label>
                    <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="password">New password</label>
                <input class="form-control" type="password" id="password" name="password" required minlength="<?= (int) setting('Auth.minimumPasswordLength') ?>" autocomplete="new-password">
                <div class="form-text">At least <?= (int) setting('Auth.minimumPasswordLength') ?> characters. Avoid common or reused passwords.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirm">Confirm new password</label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
            <button class="btn btn-primary" type="submit">Save password</button>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
