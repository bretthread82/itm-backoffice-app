<?php
$links = static function (): void {
    foreach (nav_items() as $item) {
        $active = nav_active($item['url']);
        echo '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '" href="' . site_url($item['url'])
            . '"' . ($active ? ' aria-current="page"' : '') . '><i class="bi bi-' . esc($item['icon'], 'attr') . '"></i><span>'
            . esc($item['label']) . '</span></a></li>';
    }
};
?>
<!-- Desktop sidebar -->
<aside class="app-sidebar d-none d-lg-flex flex-column">
    <a class="app-brand" href="<?= site_url('/') ?>"><i class="bi bi-grid-1x2-fill"></i><span>ITM Back Office</span></a>
    <ul class="nav nav-pills flex-column gap-1 px-3"><?php $links(); ?></ul>
</aside>

<!-- Mobile sidebar -->
<div class="offcanvas offcanvas-start app-sidebar-mobile" tabindex="-1" id="mobileNav" aria-label="Navigation">
    <div class="offcanvas-header">
        <span class="app-brand p-0"><i class="bi bi-grid-1x2-fill"></i><span>ITM Back Office</span></span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <ul class="nav nav-pills flex-column gap-1"><?php $links(); ?></ul>
    </div>
</div>
