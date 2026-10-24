<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php foreach ($sections as $key => $section): ?>
    <section class="mb-4" aria-labelledby="admin-section-<?= esc($key, 'attr') ?>">
        <h2 class="h6 text-uppercase text-body-secondary mb-3" id="admin-section-<?= esc($key, 'attr') ?>">
            <i class="bi bi-<?= esc($section['icon'], 'attr') ?> me-1" aria-hidden="true"></i><?= esc($section['label']) ?>
        </h2>
        <div class="row g-3">
            <?php foreach ($section['items'] as $item): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <a class="card itm-tile" href="<?= site_url($item['url']) ?>">
                        <span class="itm-tile-icon"><i class="bi bi-<?= esc($item['icon'], 'attr') ?>" aria-hidden="true"></i></span>
                        <span class="min-w-0">
                            <span class="itm-tile-title d-block"><?= esc($item['label']) ?></span>
                            <?php if (! empty($item['summary'])): ?><span class="itm-tile-text d-block"><?= esc($item['summary']) ?></span><?php endif; ?>
                        </span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
<?= $this->endSection() ?>
