<?php
/**
 * Company context for the topbar: none -> nothing, single -> static label, multi -> selector.
 * The selector only narrows within what AccessScope already allows.
 *
 * @var string $companyMode
 * @var list<array<string, mixed>> $companyList
 * @var int|null $companyCurrent
 * @var bool $groupLevel
 */
if ($companyMode === 'none') {
    return;
}
$label = $groupLevel ? 'Maloney Group – All Entities' : 'All my companies';
foreach ($companyList as $c) {
    if ($companyCurrent !== null && (int) $c['id'] === $companyCurrent) {
        $label = $c['name'];
    }
}
if ($companyMode === 'single') {
    $label = $companyList[0]['name'];
}
$returnTo = '/' . ltrim(service('request')->getUri()->getPath(), '/');
$rq       = (string) service('request')->getUri()->getQuery();
if ($rq !== '') {
    $returnTo .= '?' . $rq;
}
?>
<?php if ($companyMode === 'single'): ?>
    <span class="mg-company" title="Your company"><i class="bi bi-building"></i><?= esc($label) ?></span>
<?php else: ?>
    <div class="dropdown">
        <a class="mg-company dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-building"></i><?= esc($label) ?></a>
        <form method="post" action="<?= site_url('company-context') ?>" class="dropdown-menu dropdown-menu-company">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= esc($returnTo) ?>">
            <button class="dropdown-item <?= $companyCurrent === null ? 'active' : '' ?>" type="submit" name="company" value="0"><?= $groupLevel ? 'Maloney Group – All Entities' : 'All my companies' ?></button>
            <?php foreach ($companyList as $c): ?>
                <button class="dropdown-item <?= $companyCurrent === (int) $c['id'] ? 'active' : '' ?>" type="submit" name="company" value="<?= (int) $c['id'] ?>"><?= esc($c['name']) ?></button>
            <?php endforeach; ?>
        </form>
    </div>
<?php endif; ?>
