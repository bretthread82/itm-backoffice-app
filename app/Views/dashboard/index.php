<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<p class="text-body-secondary">Welcome back, <?= esc(current_profile()['first_name'] ?? display_name()) ?>. Here is what needs you today.</p>

<ul class="nav nav-tabs-itm mb-4 flex-nowrap overflow-auto" aria-label="Dashboard sections">
    <li class="nav-item">
        <a class="nav-link text-nowrap <?= $active === 'today' ? 'active' : '' ?>" <?= $active === 'today' ? 'aria-current="page"' : '' ?> href="<?= site_url('/') ?>"><i class="bi bi-sun me-1"></i>Today</a>
    </li>
    <?php foreach ($tabs as $key => $t): ?>
        <li class="nav-item">
            <a class="nav-link text-nowrap <?= $active === $key ? 'active' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?> href="<?= site_url('/?tab=' . $key) ?>">
                <i class="bi bi-<?= esc($t['icon']) ?> me-1"></i><?= esc($t['label']) ?>
                <?php if (($badges[$key] ?? 0) > 0): ?><span class="badge text-bg-warning ms-1"><?= (int) $badges[$key] ?><span class="visually-hidden"> need attention</span></span><?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?= $this->include('dashboard/tabs/' . $active) ?>
<?= $this->endSection() ?>
