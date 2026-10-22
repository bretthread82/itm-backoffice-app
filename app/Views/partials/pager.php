<?php $pager->setSurroundCount(2); ?>
<?php if ($pager->hasPrevious() || $pager->hasNext() || count($pager->links()) > 1): ?>
<nav aria-label="Pagination">
    <ul class="pagination pagination-sm mb-0 justify-content-center flex-wrap">
        <li class="page-item<?= $pager->hasPrevious() ? '' : ' disabled' ?>">
            <a class="page-link" href="<?= $pager->hasPrevious() ? esc($pager->getPrevious(), 'attr') : '#' ?>" aria-label="Previous">&laquo;</a>
        </li>
        <?php foreach ($pager->links() as $link): ?>
            <li class="page-item<?= $link['active'] ? ' active' : '' ?>">
                <a class="page-link" href="<?= esc($link['uri'], 'attr') ?>"><?= esc((string) $link['title']) ?></a>
            </li>
        <?php endforeach; ?>
        <li class="page-item<?= $pager->hasNext() ? '' : ' disabled' ?>">
            <a class="page-link" href="<?= $pager->hasNext() ? esc($pager->getNext(), 'attr') : '#' ?>" aria-label="Next">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>
