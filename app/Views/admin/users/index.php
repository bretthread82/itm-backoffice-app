<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<?php if (can('users.create')): ?>
    <a class="btn btn-primary" href="<?= site_url('admin/users/new') ?>"><i class="bi bi-plus-lg me-1"></i>New user</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form class="card border-0 shadow-sm mb-3" method="get">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= esc($filters['q']) ?>" placeholder="Name, email, title…">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['active', 'inactive', 'suspended'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="department_id">Department</label>
            <select class="form-select" id="department_id" name="department_id">
                <option value="">All</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filters['department_id'] == $d['id'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="role_id">Role</label>
            <select class="form-select" id="role_id" name="role_id">
                <option value="">All</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $filters['role_id'] == $r['id'] ? 'selected' : '' ?>><?= esc($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
            <button class="btn btn-outline-primary flex-fill">Filter</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('admin/users') ?>" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead>
                <tr><th>Name</th><th>Department</th><th>Roles</th><th>Source</th><th>Status</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
            <?php if (! $rows): ?>
                <tr><td colspan="6" class="text-center text-body-secondary py-5">No users match your filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $u):
                $name = $u['display_name'] ?: $u['first_name'] . ' ' . $u['last_name'];
                $badge = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'danger'][$u['status']] ?? 'secondary'; ?>
                <tr>
                    <td data-label="Name">
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar($u) ?>
                            <div>
                                <div class="fw-semibold"><?= esc($name) ?></div>
                                <div class="small text-body-secondary"><?= esc($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Department"><?= esc($u['department'] ?? '—') ?></td>
                    <td data-label="Roles"><?= esc($u['roles'] ?? '—') ?></td>
                    <td data-label="Source"><span class="badge text-bg-light border"><?= $u['source'] === 'entra' ? 'Entra' : 'Local' ?></span></td>
                    <td data-label="Status"><span class="badge text-bg-<?= $badge ?>"><?= ucfirst($u['status']) ?></span></td>
                    <td class="text-end">
                        <?php if (can('users.edit') || can('users.assign_roles')): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/users/' . $u['user_id'] . '/edit') ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent d-flex flex-column flex-md-row gap-2 justify-content-between align-items-center">
        <span class="small text-body-secondary"><?= (int) $total ?> user<?= $total === 1 ? '' : 's' ?></span>
        <?= $pager ?>
    </div>
</div>
<?= $this->endSection() ?>
