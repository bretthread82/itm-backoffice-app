<?php
$isEdit  = $role !== null;
$checked = old('permissions') !== null ? array_map('intval', (array) old('permissions')) : $selected;
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/roles') ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form method="post" action="<?= site_url($isEdit ? 'admin/roles/' . $role['id'] : 'admin/roles') ?>" class="card border-0 shadow-sm">
    <?= csrf_field() ?>
    <div class="card-body p-4">
        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" value="<?= esc(old('name', $role['name'] ?? '')) ?>" required>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="description">Description</label>
                <input class="form-control" id="description" name="description" value="<?= esc(old('description', $role['description'] ?? '')) ?>">
            </div>
        </div>

        <h2 class="h6 text-uppercase text-body-secondary mb-3">Permissions</h2>
        <?php if ($isSuper): ?>
            <div class="alert alert-info">Super Admin always has every permission, including those added by future modules.</div>
        <?php endif; ?>

        <div class="row g-3">
            <?php foreach ($grouped as $module => $perms): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="border rounded p-3 h-100">
                        <div class="fw-semibold text-capitalize mb-2"><?= esc($module) ?></div>
                        <?php foreach ($perms as $p): ?>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="permissions[]" id="p<?= $p['id'] ?>" value="<?= $p['id'] ?>"
                                    <?= $isSuper || in_array((int) $p['id'], $checked, true) ? 'checked' : '' ?> <?= $isSuper ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="p<?= $p['id'] ?>">
                                    <code class="small"><?= esc($p['key']) ?></code>
                                    <span class="d-block small text-body-secondary"><?= esc($p['description'] ?? '') ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer bg-transparent p-3 text-end">
        <a class="btn btn-link" href="<?= site_url('admin/roles') ?>">Cancel</a>
        <button class="btn btn-primary"><?= $isEdit ? 'Save role' : 'Create role' ?></button>
    </div>
</form>
<?= $this->endSection() ?>
