<?php
/**
 * KPI card. Tone: info | success | warning | danger | draft.
 *
 * @var string $statLabel
 * @var string $statValue  already escaped / trusted text
 * @var string $statSub    trusted HTML, may be empty
 * @var string $statIcon   bootstrap-icons class
 * @var string $statTone
 * @var string $statHref
 */
?>
<a class="card stat-card tone-<?= esc($statTone) ?>" href="<?= esc($statHref) ?>">
    <span class="stat-icon"><i class="bi <?= esc($statIcon) ?>"></i></span>
    <span class="min-w-0">
        <span class="stat-label d-block"><?= esc($statLabel) ?></span>
        <span class="stat-value d-block"><?= $statValue ?></span>
        <?php if ($statSub !== ''): ?><span class="stat-sub d-block"><?= $statSub ?></span><?php endif; ?>
    </span>
</a>
