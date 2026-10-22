<?php
$isEdit = $user !== null;
$v      = static fn (string $f, $default = '') => old($f, $user[$f] ?? $default);
$fieldsLocked = $isEdit && ! can('users.edit');
$dis    = $fieldsLocked ? 'disabled' : '';
$isEntra = $isEdit && ($user['source'] ?? '') === 'entra';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/users') ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($temp = session()->getFlashdata('temp_password')): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1"><i class="bi bi-key me-1"></i>One-time password</div>
        Share this securely with the user. It is shown only once, and they must change it at first sign-in:
        <code class="user-select-all fs-5 ms-1"><?= esc($temp) ?></code>
    </div>
<?php endif; ?>
<?php if ($isEntra): ?>
    <div class="alert alert-info"><i class="bi bi-microsoft me-1"></i>This account is managed by Microsoft Entra. Profile fields may be overwritten by the next directory sync.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <form method="post" action="<?= site_url($isEdit ? 'admin/users/' . $user['user_id'] : 'admin/users') ?>" class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <div class="card-body p-4">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Account</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="first_name">First name</label>
                        <input class="form-control" id="first_name" name="first_name" value="<?= esc($v('first_name')) ?>" required <?= $dis ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="last_name">Last name</label>
                        <input class="form-control" id="last_name" name="last_name" value="<?= esc($v('last_name')) ?>" required <?= $dis ?>>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= esc($v('email')) ?>" required <?= $dis ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="employee_no">Employee no.</label>
                        <input class="form-control" id="employee_no" name="employee_no" value="<?= esc($v('employee_no')) ?>" <?= $dis ?>>
                    </div>
                </div>

                <h2 class="h6 text-uppercase text-body-secondary mb-3">Position</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="job_title">Job title</label>
                        <input class="form-control" id="job_title" name="job_title" value="<?= esc($v('job_title')) ?>" <?= $dis ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="department_id">Department</label>
                        <select class="form-select" id="department_id" name="department_id" <?= $dis ?>>
                            <option value="">—</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (string) $v('department_id') === (string) $d['id'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="manager_id">Manager</label>
                        <select class="form-select" id="manager_id" name="manager_id" <?= $dis ?>>
                            <option value="">—</option>
                            <?php foreach ($managers as $m): if ($isEdit && (int) $m['user_id'] === (int) $user['user_id']) continue; ?>
                                <option value="<?= $m['user_id'] ?>" <?= (string) $v('manager_id') === (string) $m['user_id'] ? 'selected' : '' ?>>
                                    <?= esc($m['display_name'] ?: $m['first_name'] . ' ' . $m['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="start_date">Start date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?= esc($v('start_date')) ?>" <?= $dis ?>>
                    </div>
                </div>

                <h2 class="h6 text-uppercase text-body-secondary mb-3">Contact</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control" id="phone" name="phone" value="<?= esc($v('phone')) ?>" <?= $dis ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="mobile">Mobile</label>
                        <input class="form-control" id="mobile" name="mobile" value="<?= esc($v('mobile')) ?>" <?= $dis ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="location">Location</label>
                        <input class="form-control" id="location" name="location" value="<?= esc($v('location')) ?>" <?= $dis ?>>
                    </div>
                </div>

                <?php if (can('users.assign_roles') || ! $isEdit): ?>
                    <h2 class="h6 text-uppercase text-body-secondary mb-3">Roles</h2>
                    <input type="hidden" name="roles_present" value="1">
                    <div class="row g-2 mb-2">
                        <?php
                        $checked = old('roles') !== null ? array_map('intval', (array) old('roles')) : ($isEdit ? $userRoleIds : []);
                        foreach ($roles as $r): ?>
                            <div class="col-md-6">
                                <div class="form-check border rounded p-2 ps-5">
                                    <input class="form-check-input" type="checkbox" name="roles[]" id="role<?= $r['id'] ?>" value="<?= $r['id'] ?>"
                                        <?= in_array((int) $r['id'], $checked, true) || (! $isEdit && $r['key'] === 'staff' && old('roles') === null) ? 'checked' : '' ?>
                                        <?= can('users.assign_roles') ? '' : 'disabled' ?>>
                                    <label class="form-check-label d-block" for="role<?= $r['id'] ?>">
                                        <span class="fw-semibold"><?= esc($r['name']) ?></span>
                                        <span class="d-block small text-body-secondary"><?= esc($r['description'] ?? '') ?></span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent p-3 text-end">
                <a class="btn btn-link" href="<?= site_url('admin/users') ?>">Cancel</a>
                <?php if (! $fieldsLocked || can('users.assign_roles')): ?>
                    <button class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($isEdit): ?>
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary">Status</h2>
                <p class="mb-3">
                    <span class="badge text-bg-<?= ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'danger'][$user['status']] ?? 'secondary' ?>"><?= ucfirst($user['status']) ?></span>
                    <span class="badge text-bg-light border"><?= $isEntra ? 'Entra' : 'Local' ?></span>
                </p>
                <?php if (can('users.delete')): ?>
                    <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/status') ?>" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <select class="form-select form-select-sm" name="status" aria-label="New status">
                            <?php foreach (['active', 'inactive', 'suspended'] as $s): ?>
                                <option value="<?= $s ?>" <?= $user['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary">Update</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (can('users.edit') && ! $isEntra): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary">Password</h2>
                <p class="small text-body-secondary">Generates a new one-time password for this user.</p>
                <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/reset-password') ?>"
                      data-confirm="Reset this user's password?">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-warning">Reset password</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (! empty($mfaMethods) && can('users.edit')): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary">Two-step verification</h2>
                <p class="small text-body-secondary mb-2">Set up: <?= esc(implode(', ', $mfaMethods)) ?></p>
                <p class="small text-body-secondary">Use this if they have lost their device. They will have to set it up again if it is required for their role.</p>
                <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/reset-mfa') ?>"
                      data-confirm="Reset this user's two-step verification?">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-warning">Reset two-step verification</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('users.delete')): ?>
        <div class="card border-danger-subtle shadow-sm">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-danger">Danger zone</h2>
                <form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/delete') ?>"
                      data-confirm="Delete this user? They will no longer be able to sign in.">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-danger">Delete user</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
