<?php
$editing = $row !== null;
$action  = $editing ? site_url('admin/backups/destinations/' . (int) $row['id']) : site_url('admin/backups/destinations');
$val     = static fn (string $key, mixed $default = '') => old('s_' . $key, $values[$key] ?? $default);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/backups') ?>">Cancel</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($def['note'] !== ''): ?><div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= esc($def['note']) ?></div><?php endif; ?>

<form method="post" action="<?= $action ?>" class="card border-0 shadow-sm" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" maxlength="120" required value="<?= esc(old('name', $row['name'] ?? '')) ?>" placeholder="e.g. Finance SharePoint">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="keep_last">Backups to keep</label>
                <input class="form-control" type="number" min="1" max="3650" id="keep_last" name="keep_last" required value="<?= esc(old('keep_last', $row['keep_last'] ?? 14)) ?>">
                <div class="form-text">Older ones are deleted here after each run.</div>
            </div>

            <?php foreach ($def['fields'] as $key => $f):
                $id = 's_' . $key;
            ?>
                <div class="col-md-6">
                <?php if ($f['kind'] === 'checkbox'): ?>
                    <div class="form-check mt-md-4 pt-md-2">
                        <input class="form-check-input" type="checkbox" id="<?= $id ?>" name="<?= $id ?>" value="1" <?= $val($key, false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= $id ?>"><?= esc($f['label']) ?></label>
                        <?php if (! empty($f['help'])): ?><div class="form-text"><?= esc($f['help']) ?></div><?php endif; ?>
                    </div>
                <?php else: ?>
                    <label class="form-label" for="<?= $id ?>"><?= esc($f['label']) ?><?= empty($f['required']) ? ' <span class="text-body-secondary small">(optional)</span>' : '' ?></label>
                    <?php if ($f['kind'] === 'select'): ?>
                        <select class="form-select" id="<?= $id ?>" name="<?= $id ?>">
                            <?php foreach ($f['options'] as $optKey => $optLabel): ?>
                                <option value="<?= esc($optKey, 'attr') ?>" <?= (string) $val($key, $f['default'] ?? '') === (string) $optKey ? 'selected' : '' ?>><?= esc($optLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($f['kind'] === 'password'): ?>
                        <input class="form-control" type="password" id="<?= $id ?>" name="<?= $id ?>" autocomplete="new-password"
                               placeholder="<?= in_array($key, $secretsSet, true) ? 'Saved. Leave blank to keep it.' : '' ?>" <?= ! empty($f['required']) && ! in_array($key, $secretsSet, true) ? 'required' : '' ?>>
                    <?php else: ?>
                        <input class="form-control" id="<?= $id ?>" name="<?= $id ?>" maxlength="500" value="<?= esc($val($key, $f['default'] ?? '')) ?>" <?= ! empty($f['required']) ? 'required' : '' ?>>
                    <?php endif; ?>
                    <?php if (! empty($f['help'])): ?><div class="form-text"><?= esc($f['help']) ?></div><?php endif; ?>
                <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= old('name') !== null ? (old('enabled') ? 'checked' : '') : (($row['enabled'] ?? 1) ? 'checked' : '') ?>>
                    <label class="form-check-label" for="enabled">Send backups here</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-transparent d-flex gap-2">
        <button class="btn btn-primary"><?= $editing ? 'Save changes' : 'Save destination' ?></button>
        <a class="btn btn-outline-secondary" href="<?= site_url('admin/backups') ?>">Cancel</a>
    </div>
</form>
<?= $this->endSection() ?>
