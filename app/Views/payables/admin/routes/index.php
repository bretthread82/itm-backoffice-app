<?php
// Routing import is for managers and administrators; a Workflow Manager maintains routes but cannot open it.
$canImport = (int) (auth()->user()->is_admin ?? 0) === 1 || (int) (auth()->user()->is_manager ?? 0) === 1;
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Routes <span class="text-body-secondary fs-6">(<?= (int) $total ?>)</span></h1>
    <div class="d-flex gap-2">
        <?php if ($canImport): ?><a class="btn btn-outline-secondary" href="<?= site_url('admin/import') ?>"><i class="bi bi-upload me-1"></i>Import routing</a><?php endif; ?>
        <a class="btn btn-primary" href="<?= site_url('payables-admin/routes/new') ?>"><i class="bi bi-plus-lg me-1"></i>New route</a>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select class="form-select" name="company" aria-label="Company">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $f['company'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="status" aria-label="Status">
            <?php foreach (['' => 'Any status', 'active' => 'Active', 'inactive' => 'Inactive'] as $v => $l): ?><option value="<?= $v ?>" <?= $f['status'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="variant" aria-label="Variant">
            <?php foreach (['' => 'All variants'] + $variants as $v => $l): ?><option value="<?= $v ?>" <?= $f['variant'] === $v ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3"><input class="form-control" type="search" name="q" value="<?= esc($f['q']) ?>" placeholder="Invoice type" aria-label="Invoice type"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit">Filter</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-cards">
            <thead><tr><th>Company</th><th>Invoice type</th><th>Steps</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($routes as $r): ?>
                <tr>
                    <td data-label="Company" class="fw-semibold"><?= esc($r['code']) ?></td>
                    <td data-label="Type"><?= esc($r['type_name']) ?><?= $r['variant'] !== 'default' ? ' <span class="badge text-bg-info">' . esc($variants[$r['variant']] ?? $r['variant']) . '</span>' : '' ?><?= (int) $r['high_priority'] === 1 ? ' <span class="badge text-bg-danger">High priority</span>' : '' ?></td>
                    <td data-label="Steps"><?= (int) $r['step_count'] ?></td>
                    <td data-label="Status">
                        <?= $r['active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?>
                        <?= $r['variant'] !== 'default' ? '<span class="badge text-bg-light border">Trigger: ' . esc(str_replace('_', ' ', $r['variant_trigger'])) . '</span>' : '' ?>
                    </td>
                    <td class="actions text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('payables-admin/routes/' . $r['id'] . '/edit') ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($routes === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">No routes match. <?php if ($canImport): ?><a href="<?= site_url('admin/import') ?>">Import the routing list</a> or <?php endif; ?>add a route.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination flex-wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('payables-admin/routes') . '?' . http_build_query($f + ['page' => $p]) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
<?= $this->endSection() ?>
