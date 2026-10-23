<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge text-bg-success fs-6"><?= (int) $preview['counts']['create'] ?> to create</span>
    <span class="badge text-bg-primary fs-6"><?= (int) $preview['counts']['update'] ?> to update</span>
    <span class="badge text-bg-<?= $preview['counts']['error'] ? 'danger' : 'secondary' ?> fs-6"><?= (int) $preview['counts']['error'] ?> with errors</span>
</div>

<?php if (! $preview['ok']): ?>
    <div class="alert alert-danger">This file can't be imported yet. Fix the rows marked below and upload it again. Nothing has been saved.</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table itm-table table-cards">
            <thead><tr><th>Line</th><th>Code</th><th>Name</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($preview['rows'] as $r): ?>
                <tr class="<?= $r['action'] === 'error' ? 'table-danger' : '' ?>">
                    <td data-label="Line"><?= esc($r['line']) ?></td>
                    <td data-label="Code"><?= esc($r['code']) ?></td>
                    <td data-label="Name"><?= esc($r['name']) ?></td>
                    <td data-label="Result">
                        <?php if ($r['action'] === 'error'): ?>
                            <?php foreach ($r['errors'] as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?>
                        <?php else: ?>
                            <span class="badge text-bg-<?= $r['action'] === 'create' ? 'success' : 'primary' ?>"><?= $r['action'] === 'create' ? 'Create' : 'Update' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex gap-2">
    <?php if ($preview['ok']): ?>
        <form method="post" action="<?= site_url('admin/suppliers/upload/commit') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit">Confirm and import</button>
        </form>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/suppliers/upload') ?>">Start again</a>
</div>
<?= $this->endSection() ?>
