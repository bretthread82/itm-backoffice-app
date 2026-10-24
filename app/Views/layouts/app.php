<?php
/**
 * Main application shell (ITM theme). Child views call $this->extend('layouts/app') and
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
    <link rel="stylesheet" href="<?= base_url('assets/css/itm.css') ?>">
    <script src="<?= base_url('assets/js/theme-boot.js') ?>"></script>
</head>
<body>
<a class="visually-hidden-focusable" href="#main">Skip to content</a>

<?= $this->include('partials/sidebar') ?>

<div class="itm-main">
    <?= $this->include('partials/topbar') ?>

    <main id="main" class="itm-content">
        <?= $this->include('partials/flash') ?>

        <div class="page-header">
            <div>
                <?php if (admin_entry_for() !== null): ?>
                    <div class="itm-crumb"><a href="<?= site_url('admin') ?>"><i class="bi bi-arrow-left-short" aria-hidden="true"></i>Administration</a></div>
                <?php endif; ?>
                <h1><?= esc($title ?? '') ?></h1>
                <?php if (! empty($subtitle)): ?><div class="subtitle"><?= esc($subtitle) ?></div><?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2"><?= $this->renderSection('actions') ?></div>
        </div>

        <?= $this->renderSection('content') ?>
    </main>

    <footer class="itm-footer">&copy; <?= date('Y') ?> ITM Back Office</footer>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
