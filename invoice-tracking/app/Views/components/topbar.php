<?php
/**
 * @var object|null $navUser
 * @var string $navRole
 */
$name     = (string) ($navUser?->display_name ?: $navUser?->username ?: 'Account');
$parts    = preg_split('/\s+/', trim($name)) ?: [$name];
$initials = strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
?>
<header class="mg-topbar">
    <button class="btn btn-light border-0" type="button" id="sidebar-toggle" aria-label="Toggle menu"><i class="bi bi-list fs-5"></i></button>
    <?= $this->setData($navCompany)->include('components/company_context') ?>
    <form class="mg-search" method="get" action="<?= site_url('invoices') ?>" role="search">
        <i class="bi bi-search"></i>
        <input class="form-control" type="search" name="q" id="global-search" placeholder="Search invoices, suppliers, purchase orders..." aria-label="Search invoices" autocomplete="off">
        <kbd class="d-none d-md-inline">Ctrl K</kbd>
    </form>
    <div class="ms-auto d-flex align-items-center gap-1">
        <div class="dropdown" id="bell" data-url="<?= site_url('notifications/unread') ?>">
            <a class="mg-iconbtn" href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="position-absolute badge rounded-pill bg-danger" style="top:0;right:0;font-size:.6rem" id="bell-count" hidden>0</span>
            </a>
            <div class="dropdown-menu dropdown-menu-end dropdown-menu-bell p-0">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <strong>Notifications</strong>
                    <div class="d-flex align-items-center gap-3">
                        <form method="post" action="<?= site_url('notifications/read-all') ?>" id="bell-read-all" hidden><?= csrf_field() ?><button type="submit" class="btn btn-link btn-sm p-0">Mark all read</button></form>
                        <a class="small" href="<?= site_url('notifications') ?>">See all</a>
                    </div>
                </div>
                <div id="bell-list" class="list-group list-group-flush">
                    <div class="list-group-item text-body-secondary small">Loading…</div>
                </div>
            </div>
        </div>
        <div class="dropdown ms-1">
            <a class="mg-user" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="mg-avatar"><?= esc($initials) ?></span>
                <span class="d-none d-md-block"><?= esc($name) ?><small><?= esc($navRole) ?></small></span>
                <i class="bi bi-chevron-down small d-none d-md-inline"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= site_url('account/notifications') ?>">Notification settings</a></li>
                <li><a class="dropdown-item" href="<?= site_url('account/delegations') ?>">My delegations</a></li>
                <li><a class="dropdown-item" href="<?= site_url('account/api-tokens') ?>">API tokens</a></li>
                <li><a class="dropdown-item" href="<?= site_url('account/password') ?>">Change password</a></li>
                <li><a class="dropdown-item" href="<?= site_url('help') ?>">Help &amp; manual</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= site_url('logout') ?>">Sign out</a></li>
            </ul>
        </div>
    </div>
</header>
