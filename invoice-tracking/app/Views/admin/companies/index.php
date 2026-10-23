<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Companies</h1>
    <a class="btn btn-primary" href="<?= site_url('admin/companies/new') ?>"><i class="bi bi-plus-lg me-1"></i>New company</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-cards">
            <thead><tr><th>Code</th><th>Name</th><th>Sub-group</th><th>SharePoint connection</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($companies as $c): ?>
                <tr>
                    <td data-label="Code" class="fw-semibold"><?= esc($c['code']) ?></td>
                    <td data-label="Name"><?= esc($c['name']) ?></td>
                    <td data-label="Sub-group"><?= esc($c['group_name'] ?? '—') ?></td>
                    <td data-label="SharePoint"><?= esc($c['connection_name'] ?? '—') ?></td>
                    <td data-label="Status"><?= $c['active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                    <td class="actions text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/companies/' . $c['id'] . '/edit') ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($companies === []): ?>
                <tr><td colspan="6" class="text-center text-body-secondary py-4">No companies yet. Add one, or import the routing list to create them.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
