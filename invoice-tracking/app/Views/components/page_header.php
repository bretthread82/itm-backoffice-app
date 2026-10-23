<?php
/**
 * Page title row. Right-hand actions go in $headerActions (trusted HTML built by the calling view).
 *
 * @var string $headerTitle
 * @var string $headerSubtitle
 * @var string $headerActions
 */
?>
<div class="page-header">
    <div>
        <h1><?= esc($headerTitle) ?></h1>
        <?php if (($headerSubtitle ?? '') !== ''): ?><div class="subtitle"><?= esc($headerSubtitle) ?></div><?php endif; ?>
    </div>
    <?php if (($headerActions ?? '') !== ''): ?><div class="d-flex flex-wrap gap-2"><?= $headerActions ?></div><?php endif; ?>
</div>
