<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Users <span class="text-body-secondary fs-6">(<?= (int) $total ?>)</span></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('admin/users/upload') ?>"><i class="bi bi-upload me-1"></i>Bulk upload</a>
        <form method="post" action="<?= site_url('admin/users/invite-pending') ?>" onsubmit="return confirm('Email an invitation to every active user who has not signed in yet?')">
            <?= csrf_field() ?>
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-envelope me-1"></i>Invite all pending</button>
        </form>
        <a class="btn btn-primary" href="<?= site_url('admin/users/new') ?>"><i class="bi bi-plus-lg me-1"></i>New user</a>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6"><input class="form-control" type="search" name="q" value="<?= esc($q) ?>" placeholder="Search name or email" aria-label="Search"></div>
    <div class="col-md-4">
        <select class="form-select" name="filter" aria-label="Filter">
            <?php foreach (['' => 'All users', 'never_signed_in' => 'Never signed in', 'email_missing' => 'Email missing', 'disabled' => 'Disabled', 'admins' => 'Administrators', 'managers' => 'Managers', 'workflow' => 'Workflow managers'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $filter === $v ? 'selected' : '' ?>><?= esc($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit">Filter</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-cards">
            <thead><tr><th>Name</th><th>Email</th><th>Companies</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td data-label="Name" class="fw-semibold"><?= esc($u['display_name'] ?: $u['username']) ?></td>
                    <td data-label="Email"><?= $u['email'] ? esc($u['email']) : '<span class="text-warning">missing</span>' ?></td>
                    <td data-label="Companies"><?= (int) $u['company_count'] ?></td>
                    <td data-label="Status">
                        <?= $u['active'] ? '' : '<span class="badge text-bg-secondary">Disabled</span> ' ?>
                        <?= $u['is_admin'] ? '<span class="badge text-bg-danger">Admin</span> ' : '' ?><?= $u['is_manager'] ? '<span class="badge text-bg-primary">Manager</span> ' : '' ?><?= $u['is_workflow'] ? '<span class="badge text-bg-info">Workflow</span> ' : '' ?>
                        <?= $u['group_level'] ? '<span class="badge text-bg-primary">Group-level</span> ' : '' ?>
                        <?= $u['last_active'] ? '' : '<span class="badge text-bg-light border">Not signed in</span>' ?>
                    </td>
                    <td class="actions text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/users/' . $u['id'] . '/edit') ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($users === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">No users match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('admin/users') . '?' . http_build_query(['q' => $q, 'filter' => $filter, 'page' => $p]) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
<?= $this->endSection() ?>
