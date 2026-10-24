<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-end gap-2 mb-2">
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/customers/upload') ?>"><i class="bi bi-upload me-1"></i>Bulk upload</a>
</div>
<p class="text-body-secondary">The shared customer list, used by every module that needs one. Switching a customer off removes it from pick-lists for new records; records already entered keep what they have. Open a customer to edit its details and contacts.</p>

<form method="post" action="<?= site_url('admin/customers') ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="new-code">Customer code <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="new-code" name="code" maxlength="50" required value="<?= esc((string) old('code')) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="new-name">Customer name <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="new-name" name="name" maxlength="200" required value="<?= esc((string) old('name')) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="new-currency">Currency</label>
            <select class="form-select" id="new-currency" name="default_currency">
                <option value="">Platform default</option>
                <?php foreach (invoice_currencies() as $code => $cname): ?>
                    <option value="<?= esc($code) ?>" <?= $code === strtoupper((string) old('default_currency')) ? 'selected' : '' ?>><?= esc($code . ' - ' . $cname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Add</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table itm-table align-middle mb-0 table-cards">
            <thead>
                <tr>
                    <th>Code</th><th>Name</th><th>Email</th><th>Phone</th><th>Terms</th>
                    <?php foreach ($usage as $alias): ?><th><?= esc(ucfirst(str_replace('_', ' ', $alias))) ?></th><?php endforeach; ?>
                    <th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
                <tr>
                    <td data-label="Code"><?= esc($c['code']) ?></td>
                    <td data-label="Name"><?= esc($c['name']) ?></td>
                    <td data-label="Email"><?= esc((string) $c['email']) ?></td>
                    <td data-label="Phone"><?= esc((string) $c['phone']) ?></td>
                    <td data-label="Terms"><?= $c['payment_terms_days'] === null ? '' : (int) $c['payment_terms_days'] . ' days' ?></td>
                    <?php foreach ($usage as $alias): ?><td data-label="<?= esc(ucfirst(str_replace('_', ' ', $alias))) ?>"><?= (int) $c[$alias] ?></td><?php endforeach; ?>
                    <td data-label="Status"><span class="badge-status <?= (int) $c['active'] === 1 ? 'st-success' : 'st-draft' ?>"><?= (int) $c['active'] === 1 ? 'Active' : 'Off' ?></span></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/customers/' . $c['id']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($customers === []): ?><tr><td colspan="<?= 7 + count($usage) ?>" class="text-center text-body-secondary py-4">No customers yet. Add one above or bulk upload a CSV; until then modules that use customers have nothing to choose.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
