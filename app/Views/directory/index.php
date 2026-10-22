<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<form class="card border-0 shadow-sm mb-4" method="get">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control" id="q" name="q" type="search" value="<?= esc($filters['q']) ?>" placeholder="Name, title, email…">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id">
                <option value="">All</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filters['department_id'] == $d['id'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="location">Location</label>
            <select class="form-select" id="location" name="location">
                <option value="">All</option>
                <?php foreach ($locations as $l): ?>
                    <option <?= $filters['location'] === $l ? 'selected' : '' ?>><?= esc($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-primary flex-fill">Search</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('directory') ?>" aria-label="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </div>
</form>

<?php if (! $rows): ?>
    <div class="text-center text-body-secondary py-5"><i class="bi bi-search fs-1 d-block mb-2"></i>No one matches your search.</div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($rows as $p):
        $name = $p['display_name'] ?: $p['first_name'] . ' ' . $p['last_name']; ?>
        <div class="col-12 col-sm-6 col-xl-4 col-xxl-3">
            <a class="card border-0 shadow-sm h-100 text-decoration-none text-body directory-card" href="<?= site_url('directory/' . $p['user_id']) ?>">
                <div class="card-body text-center">
                    <?= avatar($p, 'avatar-xl mb-3') ?>
                    <div class="fw-semibold"><?= esc($name) ?></div>
                    <div class="small text-body-secondary"><?= esc($p['job_title'] ?? '') ?></div>
                    <div class="small text-body-secondary"><?= esc($p['department'] ?? '') ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="mt-4 d-flex flex-column align-items-center gap-2">
    <?= $pager ?>
    <span class="small text-body-secondary"><?= (int) $total ?> people</span>
</div>
<?= $this->endSection() ?>
