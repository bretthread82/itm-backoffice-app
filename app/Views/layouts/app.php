<?php
/**
 * Main application shell. Child views call $this->extend('layouts/app') and
 * fill the 'content' section; optional sections: 'actions', 'scripts'.
 */
$profile = current_profile();
$theme   = $profile['theme'] ?? 'auto';
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= esc($theme === 'auto' ? 'light' : $theme) ?>" data-theme-pref="<?= esc($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Back Office') ?> · ITM Back Office</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <script src="<?= base_url('assets/js/theme-boot.js') ?>"></script>
</head>
<body>
<a class="visually-hidden-focusable" href="#main">Skip to content</a>

<div class="app-shell">
    <?= $this->include('partials/sidebar') ?>

    <div class="app-main">
        <?= $this->include('partials/topbar') ?>

        <main id="main" class="app-content container-fluid">
            <?= $this->include('partials/flash') ?>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                <h1 class="h3 mb-0"><?= esc($title ?? '') ?></h1>
                <div><?= $this->renderSection('actions') ?></div>
            </div>

            <?= $this->renderSection('content') ?>
        </main>

        <footer class="app-footer text-body-secondary small px-4 py-3">
            &copy; <?= date('Y') ?> ITM Back Office
        </footer>
    </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
