<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-primary" href="<?= site_url('admin/companies/new') ?>"><i class="bi bi-plus-lg me-1"></i>New company</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table itm-table table-cards">
                    <thead><tr><th>Code</th><th>Name</th><th>Group</th><th>Members</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (! $companies): ?>
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">No companies yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($companies as $c): ?>
                        <tr>
                            <td data-label="Code" class="fw-semibold"><?= esc($c['code']) ?></td>
                            <td data-label="Name"><?= esc($c['name']) ?></td>
                            <td data-label="Group"><?= esc($c['group_name'] ?? '—') ?></td>
                            <td data-label="Members"><?= (int) $c['member_count'] ?></td>
                            <td data-label="Status"><span class="badge-status st-<?= $c['active'] ? 'success' : 'draft' ?>"><?= $c['active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/companies/' . $c['id'] . '/edit') ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <form method="post" action="<?= site_url('admin/companies/groups') ?>" class="card card-body mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($editGroup['id'] ?? 0) ?>">
            <h2 class="h6 text-uppercase text-body-secondary mb-3"><?= $editGroup ? 'Edit group' : 'New group' ?></h2>
            <div class="mb-3">
                <label class="form-label" for="group_name">Name</label>
                <input class="form-control" id="group_name" name="name" value="<?= esc(old('name', $editGroup['name'] ?? '')) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="group_parent">Parent group</label>
                <select class="form-select" id="group_parent" name="parent_id">
                    <option value="">None</option>
                    <?php foreach ($groups as $g): if ($editGroup && (int) $g['id'] === (int) $editGroup['id']) continue; ?>
                        <option value="<?= $g['id'] ?>" <?= (string) old('parent_id', $editGroup['parent_id'] ?? '') === (string) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Groups organise companies. They never change who can see what.</div>
            </div>
            <div><button class="btn btn-primary">Save group</button>
                <?php if ($editGroup): ?><a class="btn btn-link" href="<?= site_url('admin/companies') ?>">Cancel</a><?php endif; ?></div>
        </form>

        <div class="card">
            <div class="card-header">Groups</div>
            <ul class="list-group list-group-flush">
                <?php if (! $groups): ?><li class="list-group-item text-body-secondary">No groups yet.</li><?php endif; ?>
                <?php foreach ($groups as $g): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= esc($g['name']) ?></span>
                        <span class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/companies?group=' . $g['id']) ?>">Edit</a>
                            <form method="post" action="<?= site_url('admin/companies/groups/' . $g['id'] . '/delete') ?>" data-confirm="Delete this group? Its companies are kept.">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" aria-label="Delete group"><i class="bi bi-trash"></i></button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
