<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-8 col-xl-7">
    <h1 class="h3 mb-3">Bulk upload invoice types</h1>
    <p class="text-body-secondary">
        Upload a CSV file. You will see a preview first, and nothing is saved until you confirm.
        Types are matched by name, so you can upload an updated list again at any time.
    </p>
    <p><a href="<?= site_url('admin/default-invoice-types/upload/template') ?>"><i class="bi bi-download me-1"></i>Download the template</a></p>

    <form method="post" action="<?= site_url('admin/default-invoice-types/upload/preview') ?>" enctype="multipart/form-data" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="file">File (.csv, up to 1 MB)</label>
            <input class="form-control" type="file" id="file" name="file" accept=".csv" required>
        </div>
        <div><button class="btn btn-primary" type="submit">Preview</button></div>
    </form>

    <h2 class="h6 mt-4">Columns</h2>
    <ul class="small text-body-secondary">
        <li><code>name</code> is required. It is added to the default list.</li>
        <li><code>company_codes</code> is optional: company codes separated by semicolons, e.g. <code>HCSRL;CHL</code>. Those companies get a copy of the type. Leave it blank to only add the type to the default list.</li>
        <li><code>active</code> is optional (Y or N). A blank cell leaves an existing type unchanged. Inactive types cannot be assigned to companies.</li>
        <li>Each company still needs an approval route for a type before invoices can use it.</li>
    </ul>
</div></div>
<?= $this->endSection() ?>
