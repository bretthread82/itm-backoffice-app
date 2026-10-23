<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-8 col-xl-7">
    <h1 class="h3 mb-3">Bulk upload users</h1>
    <p class="text-body-secondary">
        Upload a CSV or Excel file. You will see a preview first, and nothing is saved until you confirm.
        Users are matched by email, so you can upload an updated list again at any time.
    </p>
    <p><a href="<?= site_url('admin/users/upload/template') ?>"><i class="bi bi-download me-1"></i>Download the template</a></p>

    <form method="post" action="<?= site_url('admin/users/upload/preview') ?>" enctype="multipart/form-data" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="file">File (.csv or .xlsx, up to 5 MB)</label>
            <input class="form-control" type="file" id="file" name="file" accept=".csv,.xlsx" required>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="replace" name="replace" value="1">
            <label class="form-check-label" for="replace">Replace each listed user's company memberships (otherwise companies in the file are added to what they already have)</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="invite" name="invite" value="1">
            <label class="form-check-label" for="invite">Email an invitation to newly created users</label>
        </div>
        <div><button class="btn btn-primary" type="submit">Preview</button></div>
    </form>

    <h2 class="h6 mt-4">Columns</h2>
    <ul class="small text-body-secondary">
        <li><code>email</code> and <code>display_name</code> are required for new users.</li>
        <li><code>company_codes</code>: company codes separated by semicolons, e.g. <code>HCSRL;CHL</code>.</li>
        <li><code>can_submit</code>, <code>can_approve</code>, <code>group_level</code>, <code>is_admin</code>, <code>active</code>: Y or N. A blank cell leaves an existing user unchanged.</li>
        <li><code>group_level</code> and <code>is_admin</code> are applied only because you are an administrator, and each change is audited.</li>
    </ul>
</div></div>
<?= $this->endSection() ?>
