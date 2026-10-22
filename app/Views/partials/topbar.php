<?php
$name   = display_name();
$unread = unread_notification_count();
?>
<header class="app-topbar d-flex align-items-center gap-2 px-3 px-lg-4">
    <button class="btn btn-link text-body d-lg-none px-1" type="button" data-bs-toggle="offcanvas"
            data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open menu">
        <i class="bi bi-list fs-3"></i>
    </button>

    <div class="ms-auto d-flex align-items-center gap-1">
        <button class="btn btn-link text-body" type="button" id="themeToggle" aria-label="Toggle dark mode">
            <i class="bi bi-moon-stars"></i>
        </button>

        <div class="dropdown" id="notifMenu" data-feed="<?= site_url('notifications/feed') ?>">
            <button class="btn btn-link text-body position-relative" type="button" data-bs-toggle="dropdown"
                    data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger<?= $unread ? '' : ' d-none' ?>">
                    <?= $unread > 99 ? '99+' : $unread ?>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow p-0 notif-panel">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <strong>Notifications</strong>
                    <a class="small" href="<?= site_url('notifications') ?>">View all</a>
                </div>
                <div id="notifList" class="list-group list-group-flush">
                    <div class="text-center text-body-secondary small py-4">Loading…</div>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button class="btn btn-link text-body d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false">
                <?= avatar(current_profile() ?? ['first_name' => $name]) ?>
                <span class="d-none d-md-inline"><?= esc($name) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="<?= site_url('profile') ?>"><i class="bi bi-person me-2"></i>My profile</a></li>
                <li><a class="dropdown-item" href="<?= site_url('profile/security') ?>"><i class="bi bi-key me-2"></i>Password &amp; security</a></li>
                <li><a class="dropdown-item" href="<?= site_url('profile/notifications') ?>"><i class="bi bi-bell me-2"></i>Notification settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= site_url('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
            </ul>
        </div>
    </div>
</header>
