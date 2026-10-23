<?php

use App\Services\Entra\EntraConfig;

$microsoft = EntraConfig::available();
?>
<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?><?= lang('Auth.login') ?><?= $this->endSection() ?>

<?= $this->section('main') ?>
<div class="d-flex justify-content-center py-5">
    <div class="card col-12 col-md-6 col-lg-5 col-xl-4 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-1">Maloney Group Invoicing</h1>
            <p class="text-body-secondary mb-4">Sign in to continue</p>

            <?php if (session('error') !== null): ?>
                <div class="alert alert-danger" role="alert"><?= esc(session('error')) ?></div>
            <?php elseif (session('errors') !== null): ?>
                <div class="alert alert-danger" role="alert">
                    <?php foreach ((array) session('errors') as $error): ?><?= esc($error) ?><br><?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if (session('message') !== null): ?>
                <div class="alert alert-success" role="alert"><?= esc(session('message')) ?></div>
            <?php endif ?>

            <?php if ($microsoft): ?>
                <a class="btn btn-outline-dark w-100 mb-3 d-flex align-items-center justify-content-center gap-2" href="<?= site_url('auth/microsoft') ?>">
                    <svg width="18" height="18" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
                    Sign in with Microsoft
                </a>
                <div class="d-flex align-items-center text-body-secondary small mb-3"><hr class="flex-grow-1 m-0"><span class="px-2">or use your email and password</span><hr class="flex-grow-1 m-0"></div>
            <?php endif ?>

            <form action="<?= url_to('login') ?>" method="post">
                <?= csrf_field() ?>
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="floatingEmailInput" name="email" inputmode="email" autocomplete="email" placeholder="<?= lang('Auth.email') ?>" value="<?= esc(old('email')) ?>" required>
                    <label for="floatingEmailInput"><?= lang('Auth.email') ?></label>
                </div>
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="floatingPasswordInput" name="password" autocomplete="current-password" placeholder="<?= lang('Auth.password') ?>" required>
                    <label for="floatingPasswordInput"><?= lang('Auth.password') ?></label>
                </div>

                <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="remember" id="remember" class="form-check-input" <?= old('remember') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="remember"><?= lang('Auth.rememberMe') ?></label>
                    </div>
                <?php endif; ?>

                <div class="d-grid mb-3"><button type="submit" class="btn btn-primary"><?= lang('Auth.login') ?></button></div>

                <?php if (setting('Auth.allowMagicLinkLogins')): ?>
                    <p class="text-center small mb-0"><?= lang('Auth.forgotPassword') ?> <a href="<?= url_to('magic-link') ?>"><?= lang('Auth.useMagicLink') ?></a></p>
                <?php endif ?>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
