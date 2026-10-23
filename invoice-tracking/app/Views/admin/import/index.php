<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">Import routing</h1>
<p class="text-body-secondary">
    Upload the <strong>Approval Routing</strong> list (CSV or Excel). You will review exactly what will be
    created before anything is saved. Re-uploading an updated list (for example one that now has email addresses) is safe.
</p>

<form method="post" action="<?= site_url('admin/import') ?>" enctype="multipart/form-data" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-9">
            <label class="form-label" for="file">Export file (.csv or .xlsx, up to 10 MB)</label>
            <input class="form-control" type="file" id="file" name="file" accept=".csv,.xlsx" required>
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Upload and review</button></div>
    </div>
    <div class="form-text mt-2">Expected columns: Company, Invoice Type, Role Order, Role, Person, and optionally Person Email.</div>
</form>

<h2 class="h5">Previous uploads</h2>
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>File</th><th>Uploaded</th><th>By</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td data-label="File"><?= esc($b['filename']) ?></td>
                    <td data-label="Uploaded"><?= esc($b['created_at']) ?></td>
                    <td data-label="By"><?= esc($b['uploader'] ?? '—') ?></td>
                    <td data-label="Status"><span class="badge text-bg-<?= ['uploaded' => 'warning', 'committed' => 'success', 'discarded' => 'secondary'][$b['status']] ?? 'secondary' ?>"><?= esc(ucfirst($b['status'])) ?></span></td>
                    <td class="actions text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/import/' . $b['id']) ?>"><?= $b['status'] === 'uploaded' ? 'Review' : 'View' ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($batches === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">Nothing uploaded yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
