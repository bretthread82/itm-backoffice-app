<?php
$edit = $company !== null;
$v    = static fn (string $f, $default = '') => old($f, $company[$f] ?? $default);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/companies') ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-7 col-xl-6">
    <form method="post" action="<?= site_url($edit ? 'admin/companies/' . $company['id'] : 'admin/companies') ?>" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="code">Code</label>
            <input class="form-control" id="code" name="code" value="<?= esc($v('code')) ?>" maxlength="30" required>
            <div class="form-text">Short unique code, for example the entity abbreviation.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" value="<?= esc($v('name')) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="group_id">Group</label>
            <select class="form-select" id="group_id" name="group_id">
                <option value="">None</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= (string) $v('group_id') === (string) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="timezone">Timezone</label>
            <?= view('partials/timezone_select', ['id' => 'timezone', 'name' => 'timezone', 'selected' => $v('timezone', service('timezone')->appDefault())]) ?>
            <div class="form-text">Default for people in this company who have not set their own, and the calendar used for due dates.</div>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="active" name="active" value="1" <?= (int) $v('active', 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Active</label>
        </div>
        <div><button class="btn btn-primary">Save</button> <a class="btn btn-link" href="<?= site_url('admin/companies') ?>">Cancel</a></div>
    </form>
</div></div>
<?= $this->endSection() ?>
