<?php
/**
 * Company switcher. Hidden when the user has no companies. One company shows a static label;
 * several show a dropdown that narrows (never widens) what module screens display.
 */
$uid       = (int) auth()->id();
$ctxMode   = $uid > 0 ? service('activeCompany')->mode($uid) : 'none';

if ($ctxMode === 'none') {
    return;
}

$ctxList    = service('activeCompany')->available($uid);
$ctxCurrent = service('activeCompany')->current($uid);
$ctxName    = 'All companies';

foreach ($ctxList as $c) {
    if ($ctxCurrent === (int) $c['id'] || $ctxMode === 'single') {
        $ctxName = $c['name'];
    }
}
?>
<?php if ($ctxMode === 'single'): ?>
    <span class="itm-company"><i class="bi bi-building"></i><?= esc($ctxName) ?></span>
<?php else: ?>
    <div class="dropdown">
        <a class="itm-company" href="#" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Switch company">
            <i class="bi bi-building"></i><?= esc($ctxName) ?><i class="bi bi-chevron-down small"></i>
        </a>
        <div class="dropdown-menu">
            <form method="post" action="<?= site_url('company-context') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="return" value="<?= esc(uri_string(), 'attr') ?>">
                <button class="dropdown-item<?= $ctxCurrent === null ? ' active' : '' ?>" name="company_id" value="0">All companies</button>
                <?php foreach ($ctxList as $c): ?>
                    <button class="dropdown-item<?= $ctxCurrent === (int) $c['id'] ? ' active' : '' ?>" name="company_id" value="<?= (int) $c['id'] ?>"><?= esc($c['name']) ?></button>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
<?php endif; ?>
