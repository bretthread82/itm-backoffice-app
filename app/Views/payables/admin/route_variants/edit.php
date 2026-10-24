<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php $isStandard = (int) $variant['is_standard'] === 1; ?>
<div class="row"><div class="col-lg-7 col-xl-6">
    <h1 class="h3 mb-3">Edit route variant</h1>
    <form method="post" action="<?= site_url('payables-admin/route-variants/' . $variant['id']) ?>" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" maxlength="100" required value="<?= esc(old('name', $variant['name'])) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Key</label>
            <div><code><?= esc($variant['variant_key']) ?></code></div>
            <div class="form-text">Fixed. Routes and invoices refer to it.</div>
        </div>
        <?php if (! $isStandard): ?>
            <div class="mb-3">
                <label class="form-label" for="sort_order">Order</label>
                <input class="form-control" id="sort_order" name="sort_order" inputmode="numeric" value="<?= esc(old('sort_order', (string) $variant['sort_order'])) ?>">
                <div class="form-text">If more than one variant applies to an invoice, the lowest number is used.</div>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= old('active', $variant['active']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="active">Available for new routes</label>
                <div class="form-text">Switching a variant off only hides it when creating routes. Existing active routes keep working; switch those off on the route.</div>
            </div>
        <?php endif; ?>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('payables-admin/route-variants') ?>">Cancel</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>
