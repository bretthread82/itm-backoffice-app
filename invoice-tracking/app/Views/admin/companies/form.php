<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-7 col-xl-6">
    <h1 class="h3 mb-3"><?= esc($title) ?></h1>
    <form method="post" action="<?= site_url($id === null ? 'admin/companies' : 'admin/companies/' . $id) ?>" class="card card-body" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="code">Code</label>
            <input class="form-control" id="code" name="code" maxlength="50" required value="<?= esc(old('code', $company['code'])) ?>">
            <div class="form-text">Short unique code, e.g. HCSRL. It is used in bulk user uploads.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" maxlength="150" required value="<?= esc(old('name', $company['name'])) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="group_id">Sub-group</label>
            <select class="form-select" id="group_id" name="group_id">
                <option value="">— none —</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= (string) old('group_id', $company['group_id']) === (string) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">For filtering and reporting only. It does not change who can see invoices.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="sharepoint_connection_id">SharePoint connection</label>
            <select class="form-select" id="sharepoint_connection_id" name="sharepoint_connection_id">
                <option value="">— none (use the default, if one is set) —</option>
                <?php foreach ($connections as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= (string) old('sharepoint_connection_id', $company['sharepoint_connection_id']) === (string) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="logo">Logo</label>
            <?php if ($id !== null && ! empty($company['logo_file'])): ?>
                <div class="mb-2"><img src="<?= site_url('logo/company/' . $id) ?>" alt="Current logo" style="max-height:64px;max-width:240px"></div>
            <?php endif; ?>
            <input class="form-control" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
            <div class="form-text">PNG, JPG or WebP, up to 2 MB. Shown on the dashboard when this company is selected.</div>
            <?php if ($id !== null && ! empty($company['logo_file'])): ?>
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1"><label class="form-check-label" for="remove_logo">Remove the current logo</label></div>
            <?php endif; ?>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= (int) old('active', $company['active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Active</label>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('admin/companies') ?>">Cancel</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>
