<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Sign in') ?> · ITM Back Office</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/itm.css') ?>">
</head>
<body class="auth-body">
<div class="auth-brand"><span class="itm-brand-mark">I</span><span class="itm-brand-text">ITM<small>BACK OFFICE</small></span></div>
<main class="container">
    <div class="d-flex justify-content-center py-5">
        <div class="card col-12 col-md-8 col-lg-6 col-xl-4 shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-1">ITM Back Office</h1>
                <p class="text-body-secondary mb-4"><?= esc($subtitle ?? '') ?></p>
                <?= $this->include('partials/flash') ?>
                <?= $this->renderSection('content') ?>
            </div>
        </div>
    </div>
</main>
<footer class="auth-footer">
    <span class="auth-powered"><img src="<?= base_url('assets/img/powered-by-itm.png') ?>" width="440" height="102" alt="Powered by ITM, Innovative Technology Management"></span>
</footer>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
