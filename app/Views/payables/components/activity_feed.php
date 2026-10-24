<?php
/**
 * @var list<array{actor: string, text: string, invoice_id: int, at: string, override: bool}> $feedItems
 * @var int|null $feedVisible when set, only this many items show at first and a "View more" link expands the rest
 */
$feedVisible ??= null;
$feedId      = 'feed-' . substr(md5(json_encode($feedItems)), 0, 8);
?>
<?php if ($feedItems === []): ?>
    <div class="text-body-secondary small py-2">No activity yet.</div>
<?php endif; ?>
<?php foreach ($feedItems as $i => $a):
    if ($feedVisible !== null && $i === $feedVisible): ?><div class="collapse" id="<?= $feedId ?>"><?php endif;
    $parts    = preg_split('/\s+/', trim($a['actor'])) ?: [$a['actor']];
    $initials = strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')); ?>
    <div class="feed-item">
        <span class="itm-avatar sm"><?= esc($initials) ?></span>
        <div class="flex-grow-1">
            <div class="fw-semibold"><?= esc($a['actor']) ?></div>
            <a class="small text-decoration-none" href="<?= site_url('payables/' . $a['invoice_id']) ?>"><?= esc($a['text']) ?></a>
            <?= $a['override'] ? '<span class="badge text-bg-danger">Group-level</span>' : '' ?>
        </div>
        <div class="small text-body-secondary text-nowrap"><?= esc(time_ago($a['at'])) ?></div>
    </div>
<?php endforeach; ?>
<?php if ($feedVisible !== null && count($feedItems) > $feedVisible): ?>
    </div>
    <button type="button" class="btn btn-link btn-sm p-0 mt-2" id="<?= $feedId ?>-toggle" data-bs-toggle="collapse" data-bs-target="#<?= $feedId ?>" aria-expanded="false" aria-controls="<?= $feedId ?>">View more (<?= count($feedItems) - $feedVisible ?>)</button>
    <script {csp-script-nonce}>
        document.addEventListener('DOMContentLoaded', function () {
            var panel = document.getElementById('<?= $feedId ?>'), btn = document.getElementById('<?= $feedId ?>-toggle');
            panel.addEventListener('show.bs.collapse', function () { btn.textContent = 'View less'; });
            panel.addEventListener('hide.bs.collapse', function () { btn.textContent = 'View more (<?= count($feedItems) - $feedVisible ?>)'; });
        });
    </script>
<?php endif; ?>
