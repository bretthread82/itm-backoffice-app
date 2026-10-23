<?php
$link = static function (array $item): string {
    $active = nav_active($item['url'], $item['unless'] ?? null);
    $n      = nav_badge($item['badge'] ?? null);

    return '<li><a' . ($active ? ' class="active" aria-current="page"' : '') . ' href="' . site_url($item['url']) . '">'
        . '<i class="bi bi-' . esc($item['icon'], 'attr') . '"></i>' . esc($item['label'])
        . ($n > 0 ? '<span class="itm-count">' . $n . '</span>' : '') . '</a></li>';
};
?>
<aside class="itm-sidebar" id="sidebar" aria-label="Main navigation">
    <a class="itm-brand" href="<?= site_url('/') ?>">
        <span class="itm-brand-mark">I</span>
        <span class="itm-brand-text">ITM<small>BACK OFFICE</small></span>
    </a>
    <ul class="itm-nav">
        <?php foreach (nav_tree() as $entry): ?>
            <?php if (empty($entry['group'])): ?>
                <?= $link($entry) ?>
            <?php else: ?>
                <?php
                $id       = 'nav-group-' . preg_replace('/[^a-z0-9_-]/i', '', $entry['key']);
                $hasActive = false;
                $badge    = 0;
                foreach ($entry['items'] as $i) {
                    $hasActive = $hasActive || nav_active($i['url'], $i['unless'] ?? null);
                    $badge    += nav_badge($i['badge'] ?? null);
                }
                ?>
                <li class="itm-nav-group" data-nav-group="<?= esc($entry['key'], 'attr') ?>">
                    <button class="itm-nav-toggle<?= $hasActive ? ' has-active' : '' ?>" type="button" data-bs-toggle="collapse"
                            data-bs-target="#<?= $id ?>" aria-expanded="<?= $hasActive ? 'true' : 'false' ?>" aria-controls="<?= $id ?>">
                        <i class="bi bi-<?= esc($entry['icon'], 'attr') ?>"></i><?= esc($entry['label']) ?>
                        <?php if ($badge > 0): ?><span class="itm-count itm-count-collapsed"><?= $badge ?></span><?php endif; ?>
                        <i class="bi bi-chevron-down itm-chevron"></i>
                    </button>
                    <ul class="itm-nav itm-nav-sub collapse<?= $hasActive ? ' show' : '' ?>" id="<?= $id ?>">
                        <?php foreach ($entry['items'] as $i): ?><?= $link($i) ?><?php endforeach; ?>
                    </ul>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <div class="itm-sidebar-foot">Innovative Technology Management<span class="itm-sidebar-version"><?= esc(service('versions')->label()) ?></span></div>
</aside>
