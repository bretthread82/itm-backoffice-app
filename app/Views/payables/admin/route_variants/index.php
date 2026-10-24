<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
    <h1 class="h3 mb-0">Route variants</h1>
    <a class="btn btn-primary" href="<?= site_url('payables-admin/route-variants/new') ?>"><i class="bi bi-plus-lg me-1"></i>New variant</a>
</div>
<p class="text-body-secondary">A route is the approval chain for one company, invoice type and variant. <strong>Standard</strong> is always used unless another variant's trigger applies. When several variants apply, the one with the lowest order wins. Renaming a variant changes what people see; it never changes routes or invoices already in flight.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-cards">
            <thead><tr><th>Name</th><th>Key</th><th>Order</th><th>Routes</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($variants as $v): ?>
                <tr>
                    <td data-label="Name" class="fw-semibold"><?= esc($v['name']) ?><?= (int) $v['is_standard'] === 1 ? ' <span class="badge text-bg-light border">Always on</span>' : '' ?></td>
                    <td data-label="Key"><code><?= esc($v['variant_key']) ?></code></td>
                    <td data-label="Order"><?= (int) $v['is_standard'] === 1 ? '—' : (int) $v['sort_order'] ?></td>
                    <td data-label="Routes"><?= (int) $v['route_count'] ?></td>
                    <td data-label="Status"><?= (int) $v['active'] === 1 ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                    <td class="actions text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('payables-admin/route-variants/' . $v['id'] . '/edit') ?>">Edit</a>
                        <?php if ((int) $v['is_standard'] === 0 && (int) $v['route_count'] === 0): ?>
                            <form method="post" action="<?= site_url('payables-admin/route-variants/' . $v['id'] . '/delete') ?>" class="d-inline" data-confirm="Delete this variant?">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
