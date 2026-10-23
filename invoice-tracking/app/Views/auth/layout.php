<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $this->renderSection('title') ?> · Maloney Group Invoicing</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/maloney.css') ?>">
    <?= $this->renderSection('pageStyles') ?>
</head>
<body class="auth-body">
    <div class="auth-brand"><span class="mg-brand-mark">M</span><span class="mg-brand-text">MALONEY GROUP<small>OF COMPANIES</small></span></div>
    <main role="main" class="container">
        <?= $this->renderSection('main') ?>
    </main>
    <footer class="auth-footer">
        <span class="auth-powered"><img src="<?= base_url('assets/img/powered-by-itm.png') ?>" width="440" height="102" alt="Powered by ITM, Innovative Technology Management"></span>
    </footer>
    <script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
    <?= $this->renderSection('pageScripts') ?>
</body>
</html>
