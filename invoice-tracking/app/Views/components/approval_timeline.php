<?php
/**
 * Vertical approval route: green check = done, blue ring = current, grey = not started, red = returned/rejected.
 *
 * @var list<array<string, mixed>> $tlSteps
 * @var array<int, array<string, mixed>> $tlActed  approvals keyed by step order
 * @var int|null $tlCurrent
 * @var bool $tlFinished
 * @var string $tlStatus invoice status
 * @var int|null $tlBadStep step at which the invoice was returned or rejected
 */
?>
<ul class="timeline">
    <?php foreach ($tlSteps as $s):
        $n    = (int) $s['order'];
        $a    = $tlActed[$n] ?? null;
        $done = $tlFinished || $a !== null || ($tlCurrent !== null && $n < $tlCurrent);
        $now  = $tlCurrent === $n && in_array($tlStatus, ['in_approval', 'approved', 'processed'], true);
        $bad  = $tlBadStep === $n && in_array($tlStatus, ['returned', 'rejected'], true);
        $cls  = $bad ? 'tl-bad' : ($done ? 'tl-done' : ($now ? 'tl-current' : 'tl-future'));
        $icon = $bad ? 'bi-x-lg' : ($done ? 'bi-check-lg' : ($now ? 'bi-circle-fill' : 'bi-circle'));
        $who  = ($s['assignee_kind'] ?? 'user') !== 'user' ? ' (' . (['external' => 'outside party', 'team' => 'team'][$s['assignee_kind']] ?? 'outside party') . ')' : ''; ?>
        <li class="<?= $cls ?>">
            <span class="tl-dot"><i class="bi <?= $icon ?>"<?= $now ? ' style="font-size:.45rem"' : '' ?>></i></span>
            <span>
                <div class="tl-title"><?= esc($s['role'] ?: ucfirst((string) $s['step_type'])) ?></div>
                <div class="tl-meta"><?= esc(($s['label'] ?: '—') . $who) ?></div>
                <?php if ($a): ?>
                    <div class="tl-meta"><?= esc($a['actor']) ?><?= $a['acted_as'] === 'group_level' ? ' <span class="badge text-bg-danger">group-level</span>' : ($a['acted_as'] === 'delegate' ? ' <span class="badge text-bg-info">delegate</span>' : '') ?> · <?= esc(time_ago($a['acted_at'])) ?></div>
                <?php elseif ($now): ?>
                    <div class="tl-meta text-primary fw-semibold">Awaiting approval</div>
                <?php elseif ($bad): ?>
                    <div class="tl-meta text-danger fw-semibold"><?= $tlStatus === 'rejected' ? 'Rejected here' : 'Returned here' ?></div>
                <?php elseif (! $done): ?>
                    <div class="tl-meta">Not started</div>
                <?php endif; ?>
            </span>
        </li>
    <?php endforeach; ?>
</ul>
