<?php
$name   = display_name();
$unread = unread_notification_count();
?>
<header class="itm-topbar">
    <button class="btn btn-light border-0" type="button" id="sidebar-toggle" aria-label="Toggle menu"><i class="bi bi-list fs-5"></i></button>

    <?= $this->include('partials/company_context') ?>

    <div class="ms-auto d-flex align-items-center gap-1">
        <button class="itm-iconbtn btn btn-link" type="button" id="themeToggle" aria-label="Toggle dark mode">
            <i class="bi bi-moon-stars"></i>
        </button>

        <div class="dropdown" id="notifMenu" data-feed="<?= site_url('notifications/feed') ?>" data-csrf-header="<?= esc(config('Security')->headerName) ?>">
            <button class="itm-iconbtn btn btn-link" type="button" data-bs-toggle="dropdown"
                    data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span id="notifBadge" class="badge rounded-pill bg-danger<?= $unread ? '' : ' d-none' ?>">
                    <?= $unread > 99 ? '99+' : $unread ?>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow p-0 notif-panel">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <strong>Notifications</strong>
                    <span class="d-flex gap-3 align-items-center">
                        <button type="button" class="btn btn-link btn-sm p-0" id="notifReadAll" data-url="<?= site_url('notifications/read-all') ?>"<?= $unread ? '' : ' disabled' ?>>Mark all read</button>
                        <a class="small" href="<?= site_url('notifications') ?>">View all</a>
                    </span>
                </div>
                <div id="notifList" class="list-group list-group-flush">
                    <div class="text-center text-body-secondary small py-4">Loading…</div>
                </div>
            </div>
        </div>

        <div class="dropdown ms-1">
            <a class="itm-user" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                <?= avatar(current_profile() ?? ['first_name' => $name]) ?>
                <span class="d-none d-md-block"><?= esc($name) ?></span>
                <i class="bi bi-chevron-down small d-none d-md-inline"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="<?= site_url('profile') ?>"><i class="bi bi-person me-2"></i>My profile</a></li>
                <li><a class="dropdown-item" href="<?= site_url('profile/security') ?>"><i class="bi bi-key me-2"></i>Password &amp; security</a></li>
                <li><a class="dropdown-item" href="<?= site_url('profile/notifications') ?>"><i class="bi bi-bell me-2"></i>Notification settings</a></li>
                <li><a class="dropdown-item" href="<?= site_url('help') ?>"><i class="bi bi-question-circle me-2"></i>Help</a></li>
                <?php if (admin_tree() !== []): ?>
                    <li><a class="dropdown-item<?= admin_entry_for() !== null || nav_active('admin', null, null, []) ? ' active' : '' ?>" href="<?= site_url('admin') ?>"><i class="bi bi-gear me-2"></i>Administration</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= site_url('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
            </ul>
        </div>
    </div>
</header>
