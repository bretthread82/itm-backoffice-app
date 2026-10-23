<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-7 col-xl-6">
    <h1 class="h3 mb-3">New route variant</h1>
    <form method="post" action="<?= site_url('invoices-admin/route-variants') ?>" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" maxlength="100" required value="<?= esc(old('name')) ?>">
            <div class="form-text">Shown on the routes list and when creating a route.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="variant_key">Key <span class="text-body-secondary">(optional)</span></label>
            <input class="form-control" id="variant_key" name="variant_key" maxlength="20" pattern="[a-z0-9_]*" value="<?= esc(old('variant_key')) ?>">
            <div class="form-text">Lower-case letters, numbers and underscores. Made from the name if left blank. It cannot be changed later.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="sort_order">Order</label>
            <input class="form-control" id="sort_order" name="sort_order" inputmode="numeric" value="<?= esc(old('sort_order', '100')) ?>">
            <div class="form-text">If more than one variant applies to an invoice, the lowest number is used.</div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Add variant</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('invoices-admin/route-variants') ?>">Cancel</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>
