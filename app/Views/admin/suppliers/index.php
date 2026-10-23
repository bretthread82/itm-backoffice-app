<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-end gap-2 mb-2">
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/suppliers/upload') ?>"><i class="bi bi-upload me-1"></i>Bulk upload</a>
</div>
<p class="text-body-secondary">The shared supplier list, used by every module that needs one (for example the Supplier field on an invoice). Switching a supplier off removes it from pick-lists for new records; records already entered keep what they have.</p>

<form method="post" action="<?= site_url('admin/suppliers') ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="new-code">Supplier code</label>
            <input class="form-control" id="new-code" name="code" maxlength="50" required value="<?= esc((string) old('code')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="new-name">Supplier name</label>
            <input class="form-control" id="new-name" name="name" maxlength="200" required value="<?= esc((string) old('name')) ?>">
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Add</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>Code</th><th>Name</th><?php if (!empty($showInvoices)): ?><th>Invoices</th><?php endif; ?><th>Active</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td data-label="Code"><input class="form-control" form="s-<?= (int) $s['id'] ?>" name="code" maxlength="50" required value="<?= esc($s['code']) ?>" aria-label="Supplier code"></td>
                    <td data-label="Name"><input class="form-control" form="s-<?= (int) $s['id'] ?>" name="name" maxlength="200" required value="<?= esc($s['name']) ?>" aria-label="Supplier name"></td>
                    <?php if (!empty($showInvoices)): ?><td data-label="Invoices"><?= (int) $s['invoice_count'] ?></td><?php endif; ?>
                    <td data-label="Active">
                        <div class="form-check"><input class="form-check-input" form="s-<?= (int) $s['id'] ?>" type="checkbox" name="active" value="1" id="act-<?= (int) $s['id'] ?>" <?= (int) $s['active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="act-<?= (int) $s['id'] ?>">Active</label></div>
                    </td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-primary" type="submit" form="s-<?= (int) $s['id'] ?>">Save</button></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($suppliers === []): ?><tr><td colspan="<?= !empty($showInvoices) ? 5 : 4 ?>" class="text-center text-body-secondary py-4">No suppliers yet. Add one above or bulk upload a CSV; until then modules that use suppliers have nothing to choose.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($suppliers as $s): ?>
    <form id="s-<?= (int) $s['id'] ?>" method="post" action="<?= site_url('admin/suppliers/' . $s['id']) ?>"><?= csrf_field() ?></form>
<?php endforeach; ?>
<?= $this->endSection() ?>
