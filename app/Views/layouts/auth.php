<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Sign in') ?> · ITM Back Office</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-card card border-0 shadow">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <div class="auth-logo"><i class="bi bi-grid-1x2-fill"></i></div>
            <h1 class="h4 mt-3 mb-1">ITM Back Office</h1>
            <p class="text-body-secondary mb-0"><?= esc($subtitle ?? '') ?></p>
        </div>
        <?= $this->include('partials/flash') ?>
        <?= $this->renderSection('content') ?>
    </div>
</main>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
