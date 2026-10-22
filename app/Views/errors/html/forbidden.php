<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied · ITM Back Office</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-card card border-0 shadow text-center">
    <div class="card-body p-5">
        <div class="display-4 text-warning">403</div>
        <h1 class="h4">Access denied</h1>
        <p class="text-body-secondary">You don't have permission to view this page.</p>
        <a class="btn btn-primary" href="<?= site_url('/') ?>">Back to dashboard</a>
    </div>
</main>
</body>
</html>
