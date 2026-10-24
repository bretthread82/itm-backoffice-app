<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-8 col-xl-7">
    <p class="text-body-secondary">
        Upload a CSV file. You will see a preview first, and nothing is saved until you confirm.
        Customers are matched by code, so you can upload an updated list again at any time.
    </p>
    <p><a href="<?= site_url('admin/customers/upload/template') ?>"><i class="bi bi-download me-1"></i>Download the template</a></p>

    <form method="post" action="<?= site_url('admin/customers/upload/preview') ?>" enctype="multipart/form-data" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="file">File (.csv, up to 1 MB)</label>
            <input class="form-control" type="file" id="file" name="file" accept=".csv" required>
        </div>
        <div><button class="btn btn-primary" type="submit">Preview</button></div>
    </form>

    <h2 class="h6 mt-4">Columns</h2>
    <ul class="small text-body-secondary">
        <li><code>code</code> is required and unique, e.g. <code>C0042</code>. An existing code updates that customer.</li>
        <li><code>name</code> is required and unique.</li>
        <li><code>email</code>, <code>phone</code> and <code>payment_terms_days</code> (0 to 365) are optional. A blank cell leaves an existing customer's value unchanged.</li>
        <li><code>active</code> is optional (Y or N, default Y for new customers).</li>
        <li>Customers missing from the file are left as they are. Addresses and contacts are kept on each customer's page.</li>
    </ul>
</div></div>
<?= $this->endSection() ?>
