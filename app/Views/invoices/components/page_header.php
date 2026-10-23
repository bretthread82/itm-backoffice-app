<?php
/**
 * Sub-heading row under the layout's page title: a one-line description and the page's action buttons.
 * The title itself comes from the view's $title (layouts/app renders it). Actions are trusted HTML built by the calling view.
 *
 * @var string $headerTitle    unused (the layout prints the title)
 * @var string $headerSubtitle
 * @var string $headerActions
 */
if (($headerSubtitle ?? '') === '' && ($headerActions ?? '') === '') {
    return;
}
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="text-body-secondary"><?= esc($headerSubtitle ?? '') ?></div>
    <?php if (($headerActions ?? '') !== ''): ?><div class="d-flex flex-wrap gap-2"><?= $headerActions ?></div><?php endif; ?>
</div>
