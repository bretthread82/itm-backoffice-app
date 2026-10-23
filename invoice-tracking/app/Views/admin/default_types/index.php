<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-start gap-2">
    <h1 class="h3 mb-1">Invoice Types</h1>
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/default-invoice-types/upload') ?>"><i class="bi bi-upload me-1"></i>Bulk upload</a>
</div>
<p class="text-body-secondary">A standard list of invoice types. Assign them to companies in bulk instead of creating each type per company. Assigning adds an ordinary type to the company; it still needs an approval route before invoices can use it.</p>

<form method="post" action="<?= site_url('admin/default-invoice-types') ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-9">
            <label class="form-label" for="new-name">New default type</label>
            <input class="form-control" id="new-name" name="name" maxlength="200" required>
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Add</button></div>
    </div>
</form>

<form method="post" action="<?= site_url('admin/default-invoice-types/assign') ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">1. Default types</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($types as $t): ?>
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" name="type_ids[]" value="<?= (int) $t['id'] ?>" id="type-<?= (int) $t['id'] ?>" <?= (int) $t['active'] === 1 ? '' : 'disabled' ?>>
                            <label class="flex-grow-1" for="type-<?= (int) $t['id'] ?>"><?= esc($t['name']) ?><?= (int) $t['active'] === 1 ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?><?= (int) $t['high_priority'] === 1 ? ' <span class="badge text-bg-danger">High priority</span>' : '' ?></label>
                            <span class="text-body-secondary small"><?= (int) $t['company_count'] ?> companies</span>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int) $t['id'] ?>" aria-expanded="false">Edit</button>
                        </li>
                        <li class="list-group-item collapse bg-body-tertiary" id="edit-<?= (int) $t['id'] ?>">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-4"><input class="form-control" form="f-<?= (int) $t['id'] ?>" name="name" maxlength="200" required value="<?= esc($t['name']) ?>" aria-label="Name"></div>
                                <div class="col-md-2"><div class="form-check"><input class="form-check-input" form="f-<?= (int) $t['id'] ?>" type="checkbox" name="active" value="1" id="act-<?= (int) $t['id'] ?>" <?= (int) $t['active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="act-<?= (int) $t['id'] ?>">Active</label></div></div>
                                <div class="col-md-3"><div class="form-check"><input class="form-check-input" form="f-<?= (int) $t['id'] ?>" type="checkbox" name="high_priority" value="1" id="pri-<?= (int) $t['id'] ?>" <?= (int) $t['high_priority'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="pri-<?= (int) $t['id'] ?>">High priority</label></div></div>
                                <div class="col-md-3 d-flex gap-2 justify-content-md-end">
                                    <button class="btn btn-sm btn-outline-primary" type="submit" form="f-<?= (int) $t['id'] ?>">Save</button>
                                    <button class="btn btn-sm btn-outline-danger" type="submit" form="f-<?= (int) $t['id'] ?>" formaction="<?= site_url('admin/default-invoice-types/' . $t['id'] . '/delete') ?>"
                                            onclick="return confirm('Delete this default type? Copies already in companies are kept.')">Delete</button>
                                </div>
                            </div>
                            <div class="form-text">Renaming does not change copies already in companies. High priority does: it applies to every company copy for invoices submitted from now on, and approvers get an email they cannot switch off.</div>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($types === []): ?><li class="list-group-item text-center text-body-secondary py-4">No default types yet. Add one above.</li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>2. Companies</span>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="toggle-all-companies">Select all</button>
                </div>
                <ul class="list-group list-group-flush" style="max-height: 24rem; overflow-y: auto;">
                    <?php foreach ($companies as $c): ?>
                        <li class="list-group-item">
                            <div class="form-check">
                                <input class="form-check-input company-check" type="checkbox" name="company_ids[]" value="<?= (int) $c['id'] ?>" id="co-<?= (int) $c['id'] ?>">
                                <label class="form-check-label" for="co-<?= (int) $c['id'] ?>"><?= esc($c['name']) ?></label>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($companies === []): ?><li class="list-group-item text-center text-body-secondary py-4">No active companies.</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="mt-3"><button class="btn btn-primary" type="submit">Assign selected types to selected companies</button></div>
</form>

<h2 class="h4 mt-5">All invoice types by company <span class="text-body-secondary fs-6">(<?= (int) $total ?>)</span></h2>
<p class="text-body-secondary">Every type in the application, whether it came from a default or was made for one company. Switch on <strong>High priority</strong> and approvers get an email and bell alert for these invoices that they cannot turn off. It applies to invoices submitted from now on. Saving a default type above overwrites the setting on its company copies.</p>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select class="form-select" name="company" aria-label="Company">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $f['company'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="source" aria-label="Source">
            <?php foreach (['' => 'Any source', 'default' => 'From a default', 'custom' => 'Custom'] as $v => $l): ?><option value="<?= $v ?>" <?= $f['source'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="priority" aria-label="Priority">
            <?php foreach (['' => 'Any priority', 'on' => 'High priority', 'off' => 'Not high priority'] as $v => $l): ?><option value="<?= $v ?>" <?= $f['priority'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3"><input class="form-control" type="search" name="q" value="<?= esc($f['q']) ?>" placeholder="Type name" aria-label="Type name"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit">Filter</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-cards">
            <thead><tr><th>Company</th><th>Invoice type</th><th>Source</th><th>Routes</th><th>High priority</th></tr></thead>
            <tbody>
            <?php foreach ($companyTypes as $t): ?>
                <tr>
                    <td data-label="Company" class="fw-semibold"><?= esc($t['code']) ?></td>
                    <td data-label="Type"><?= esc($t['name']) ?><?= (int) $t['active'] === 1 ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></td>
                    <td data-label="Source"><?= $t['default_type_id'] !== null ? '<span class="badge text-bg-light border">Default</span>' : '<span class="badge text-bg-info">Custom</span>' ?></td>
                    <td data-label="Routes"><a href="<?= site_url('admin/routes') . '?' . http_build_query(['q' => $t['name']]) ?>"><?= (int) $t['route_count'] ?></a></td>
                    <td data-label="High priority">
                        <form method="post" action="<?= site_url('admin/invoice-types/' . $t['id'] . '/priority') ?>" class="d-flex align-items-center gap-2 mb-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return" value="admin/default-invoice-types">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="high_priority" value="1" id="hp-<?= (int) $t['id'] ?>" aria-label="High priority for <?= esc($t['name']) ?> (<?= esc($t['code']) ?>)" <?= (int) $t['high_priority'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
                            </div>
                            <?= (int) $t['high_priority'] === 1 ? '<span class="badge text-bg-danger">High priority</span>' : '' ?>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($companyTypes === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">No invoice types match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination flex-wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('admin/default-invoice-types') . '?' . http_build_query($f + ['page' => $p]) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php foreach ($types as $t): ?>
    <form id="f-<?= (int) $t['id'] ?>" method="post" action="<?= site_url('admin/default-invoice-types/' . $t['id']) ?>"><?= csrf_field() ?></form>
<?php endforeach; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('toggle-all-companies')?.addEventListener('click', function () {
    var boxes = document.querySelectorAll('.company-check');
    var all = Array.prototype.every.call(boxes, function (b) { return b.checked; });
    boxes.forEach(function (b) { b.checked = !all; });
    this.textContent = all ? 'Select all' : 'Clear all';
});
</script>
<?= $this->endSection() ?>
