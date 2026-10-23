<?php
/**
 * Accordion of questions. Each item has the id in $faq['id'] so search results can link to it.
 *
 * @var list<array<string, mixed>> $faqs
 * @var string $prefix unique per accordion on the page
 */
?>
<div class="accordion" id="acc-<?= esc($prefix, 'attr') ?>">
    <?php foreach ($faqs as $f): ?>
        <div class="accordion-item" id="<?= esc($f['id'], 'attr') ?>">
            <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                        data-bs-target="#<?= esc($f['id'], 'attr') ?>-body" aria-expanded="false" aria-controls="<?= esc($f['id'], 'attr') ?>-body">
                    <?= esc($f['q']) ?>
                </button>
            </h3>
            <div id="<?= esc($f['id'], 'attr') ?>-body" class="accordion-collapse collapse" data-bs-parent="#acc-<?= esc($prefix, 'attr') ?>">
                <div class="accordion-body"><?= esc($f['a']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
