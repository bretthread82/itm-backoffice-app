<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-12 col-lg-4">
        <form method="post" action="<?= site_url('admin/departments') ?>" class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3"><?= $editing ? 'Edit department' : 'New department' ?></h2>
                <div class="mb-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="<?= esc(old('name', $editing['name'] ?? '')) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="parent_id">Parent department</label>
                    <select class="form-select" id="parent_id" name="parent_id">
                        <option value="">None</option>
                        <?php foreach ($departments as $d): if ($editing && (int) $d['id'] === (int) $editing['id']) continue; ?>
                            <option value="<?= $d['id'] ?>" <?= (string) old('parent_id', $editing['parent_id'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary">Save</button>
                <?php if ($editing): ?><a class="btn btn-link" href="<?= site_url('admin/departments') ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Name</th><th>Parent</th><th>Staff</th><th></th></tr></thead>
                    <tbody>
                    <?php if (! $departments): ?>
                        <tr><td colspan="4" class="text-center text-body-secondary py-5">No departments yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($departments as $d): ?>
                        <tr>
                            <td data-label="Name" class="fw-semibold"><?= esc($d['name']) ?></td>
                            <td data-label="Parent"><?= esc($d['parent_name'] ?? '—') ?></td>
                            <td data-label="Staff"><?= (int) $d['staff_count'] ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/departments?edit=' . $d['id']) ?>">Edit</a>
                                <form class="d-inline" method="post" action="<?= site_url('admin/departments/' . $d['id'] . '/delete') ?>"
                                      data-confirm="Delete this department? Staff will be left without a department.">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
