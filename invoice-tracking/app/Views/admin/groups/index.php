<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">Sub-groups</h1>
<p class="text-body-secondary">For organising companies in filters and reports. They never grant or limit who can see invoices.</p>

<?php $names = array_column($groups, 'name', 'id'); ?>

<form method="post" action="<?= site_url('admin/groups') ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label" for="new-name">New sub-group</label>
            <input class="form-control" id="new-name" name="name" maxlength="150" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="new-parent">Inside</label>
            <select class="form-select" id="new-parent" name="parent_id">
                <option value="">— top level —</option>
                <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>"><?= esc($g['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Add</button></div>
    </div>
</form>

<div class="card">
    <ul class="list-group list-group-flush">
        <?php foreach ($groups as $g): ?>
            <li class="list-group-item">
                <form method="post" action="<?= site_url('admin/groups/' . $g['id']) ?>" class="row g-2 align-items-center">
                    <?= csrf_field() ?>
                    <div class="col-md-4"><input class="form-control" name="name" maxlength="150" required value="<?= esc($g['name']) ?>" aria-label="Name"></div>
                    <div class="col-md-3">
                        <select class="form-select" name="parent_id" aria-label="Parent">
                            <option value="">— top level —</option>
                            <?php foreach ($groups as $p): if ((int) $p['id'] === (int) $g['id']) { continue; } ?>
                                <option value="<?= $p['id'] ?>" <?= (int) $g['parent_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 text-body-secondary small"><?= (int) $g['company_count'] ?> companies</div>
                    <div class="col-md-3 d-flex gap-2 justify-content-md-end">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                        <button class="btn btn-sm btn-outline-danger" type="submit" formaction="<?= site_url('admin/groups/' . $g['id'] . '/delete') ?>"
                                onclick="return confirm('Delete this sub-group?')">Delete</button>
                    </div>
                </form>
            </li>
        <?php endforeach; ?>
        <?php if ($groups === []): ?><li class="list-group-item text-center text-body-secondary py-4">No sub-groups yet.</li><?php endif; ?>
    </ul>
</div>
<?= $this->endSection() ?>
