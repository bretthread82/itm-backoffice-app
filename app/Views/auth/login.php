<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php if ($msg = session('error')): ?>
    <div class="alert alert-danger"><?= esc($msg) ?></div>
<?php endif; ?>
<?php if ($errs = session('errors')): ?>
    <div class="alert alert-danger"><?php foreach ((array) $errs as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (config(\Config\Entra::class)->isReady()): ?>
    <a class="btn btn-outline-secondary w-100 mb-3" href="<?= site_url('auth/entra/login') ?>">
        <i class="bi bi-microsoft me-2"></i>Sign in with Microsoft
    </a>
    <div class="text-center text-body-secondary small mb-3">or use your local account</div>
<?php endif; ?>

<form action="<?= url_to('login') ?>" method="post">
    <?= csrf_field() ?>
    <div class="form-floating mb-3">
        <input type="email" class="form-control" id="email" name="email" placeholder="Email"
               inputmode="email" autocomplete="email" value="<?= old('email') ?>" required autofocus>
        <label for="email">Email address</label>
    </div>
    <div class="form-floating mb-3">
        <input type="password" class="form-control" id="password" name="password" placeholder="Password"
               autocomplete="current-password" required>
        <label for="password">Password</label>
    </div>
    <?php if (setting('Auth.sessionConfig')['allowRemembering'] ?? false): ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" <?= old('remember') ? 'checked' : '' ?>>
            <label class="form-check-label" for="remember">Remember me</label>
        </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary w-100 btn-lg">Sign in</button>
    <div class="text-center mt-3"><a class="small" href="<?= site_url('forgot-password') ?>">Forgot your password?</a></div>
</form>
<?= $this->endSection() ?>
