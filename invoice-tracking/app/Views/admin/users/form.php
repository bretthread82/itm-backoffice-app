<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-xl-9">
    <h1 class="h3 mb-3"><?= esc($title) ?></h1>
    <form method="post" action="<?= site_url($id === null ? 'admin/users' : 'admin/users/' . $id) ?>" class="card card-body mb-3">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="display_name">Name</label>
                <input class="form-control" id="display_name" name="display_name" maxlength="150" required value="<?= esc(old('display_name', $user['display_name'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <?php if ($id === null): ?>
                    <input class="form-control" type="email" id="email" name="email" maxlength="254" required value="<?= esc(old('email')) ?>">
                <?php else: ?>
                    <input class="form-control" type="email" id="email" name="email" maxlength="254" placeholder="<?= ($user['email'] ?? '') === '' ? 'No email yet' : '' ?>" value="<?= esc(old('email', $user['email'] ?? '')) ?>">
                <?php endif; ?>
            </div>
        </div>

        <h2 class="h6 mt-4">Companies</h2>
        <p class="text-body-secondary small mb-2">The companies this user can see. "Can submit" lets them upload invoices for that company; "Can approve" lets them act on approval steps for it (needed to be named on a route).</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle table-cards">
                <thead><tr><th>Member</th><th>Company</th><th>Can submit</th><th>Can approve</th><th>Job title</th></tr></thead>
                <tbody>
                <?php foreach ($companies as $c): $m = $memberships[$c['id']] ?? null; ?>
                    <tr>
                        <td data-label="Member"><input class="form-check-input" type="checkbox" name="member[<?= $c['id'] ?>]" value="1" <?= $m ? 'checked' : '' ?> aria-label="Member of <?= esc($c['code']) ?>"></td>
                        <td data-label="Company"><span class="fw-semibold"><?= esc($c['code']) ?></span> <span class="text-body-secondary"><?= esc($c['name']) ?></span><?= $c['active'] ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></td>
                        <td data-label="Can submit"><input class="form-check-input" type="checkbox" name="submit[<?= $c['id'] ?>]" value="1" <?= ($m['can_submit'] ?? 0) ? 'checked' : '' ?> aria-label="Can submit for <?= esc($c['code']) ?>"></td>
                        <td data-label="Can approve"><input class="form-check-input" type="checkbox" name="approve[<?= $c['id'] ?>]" value="1" <?= ($m['can_approve'] ?? 0) ? 'checked' : '' ?> aria-label="Can approve for <?= esc($c['code']) ?>"></td>
                        <td data-label="Job title"><input class="form-control form-control-sm" name="title[<?= $c['id'] ?>]" maxlength="150" value="<?= esc($m['job_title'] ?? '') ?>"></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($companies === []): ?><tr><td colspan="5" class="text-body-secondary">Add companies first.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <h2 class="h6 mt-3">Access</h2>
        <?php if ($actorIsAdmin): ?>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="group_level" name="group_level" value="1" <?= $groupLevel ? 'checked' : '' ?>>
            <label class="form-check-label" for="group_level"><strong>Group-level user</strong>: can view and approve invoices for every company (fully audited).</label>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="is_admin" name="is_admin" value="1" <?= ($user['is_admin'] ?? 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_admin"><strong>Administrator</strong>: everything a manager can do, plus system settings, SharePoint, API tokens and group-level users.</label>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="is_manager" name="is_manager" value="1" <?= ($user['is_manager'] ?? 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_manager"><strong>Manager</strong>: can manage users, teams, companies and approval routing, but not system settings.</label>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="is_workflow" name="is_workflow" value="1" <?= ($user['is_workflow'] ?? 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_workflow"><strong>Workflow Manager</strong>: can maintain invoice types, suppliers, approval routes and route variants, and nothing else. Not combined with Manager.</label>
        </div>
        <?php endif; ?>
        <?php if ($id !== null): ?>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= ($user['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="active">Active (untick to stop this user signing in)</label>
            </div>
        <?php else: ?>
            <div class="form-check mb-2 mt-3">
                <input class="form-check-input" type="checkbox" id="send_invite" name="send_invite" value="1" checked>
                <label class="form-check-label" for="send_invite">Email an invitation so they can set a password</label>
            </div>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('admin/users') ?>">Back</a>
        </div>
    </form>

    <?php if ($id !== null && $offboard !== null): ?>
        <form method="post" action="<?= site_url('admin/users/' . $id . '/transfer-work') ?>" class="card card-body mb-3" onsubmit="return confirm('Transfer this user\'s work to the replacement?')">
            <?= csrf_field() ?>
            <h2 class="h6">Leaving the company? Hand over their work</h2>
            <p class="text-body-secondary small mb-2">
                Moves to the replacement: <strong><?= count($offboard['steps']) ?></strong> upcoming approval step(s) on invoices in progress,
                <strong><?= (int) $offboard['route_steps'] ?></strong> approval route step(s), and <strong><?= (int) $offboard['submitted'] ?></strong> unfinished invoice(s) they submitted.
                Their <strong><?= (int) $offboard['delegations'] ?></strong> active delegation(s) are ended. Past actions and completed invoices keep the original name.
                The replacement must be a member of the affected companies.
            </p>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="replacement_id">Replacement</label>
                    <select class="form-select" id="replacement_id" name="replacement_id" required>
                        <option value="">Choose a user…</option>
                        <?php foreach ($replacements as $r): ?><option value="<?= (int) $r['id'] ?>"><?= esc($r['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="disable" name="disable" value="1" <?= $isSelf ? 'disabled' : 'checked' ?>>
                        <label class="form-check-label" for="disable">Also disable this account (they can no longer sign in)</label>
                    </div>
                </div>
            </div>
            <div class="mt-3"><button class="btn btn-outline-danger" type="submit"><i class="bi bi-person-dash me-1"></i>Transfer work</button></div>
        </form>
    <?php endif; ?>

    <?php if ($id !== null && ! empty($user['entra_oid'])): ?>
        <form method="post" action="<?= site_url('admin/users/' . $id . '/unlink-microsoft') ?>" class="mb-3" onsubmit="return confirm('Unlink this Microsoft account?')">
            <?= csrf_field() ?>
            <span class="small me-2"><i class="bi bi-microsoft me-1"></i>Linked to a Microsoft account.</span>
            <button class="btn btn-outline-secondary btn-sm" type="submit">Unlink</button>
        </form>
    <?php endif; ?>

    <?php if ($id !== null && ! empty($user['email'])): ?>
        <form method="post" action="<?= site_url('admin/users/' . $id . '/reset-password') ?>" class="card card-body mb-3" autocomplete="off">
            <?= csrf_field() ?>
            <h2 class="h6">Reset password</h2>
            <p class="text-body-secondary small mb-2">Set a temporary password for this local account. The user must choose their own when they next sign in. Share it with them securely; it is not shown again or emailed.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="new_password">Temporary password</label>
                    <input class="form-control" type="password" id="new_password" name="new_password" required minlength="<?= (int) setting('Auth.minimumPasswordLength') ?>" autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="new_password_confirm">Confirm password</label>
                    <input class="form-control" type="password" id="new_password_confirm" name="new_password_confirm" required autocomplete="new-password">
                </div>
            </div>
            <div class="mt-3"><button class="btn btn-outline-danger" type="submit" onclick="return confirm('Replace this user\'s password?')"><i class="bi bi-key me-1"></i>Set temporary password</button></div>
        </form>

        <form method="post" action="<?= site_url('admin/users/' . $id . '/invite') ?>" class="mb-4">
            <?= csrf_field() ?>
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-envelope me-1"></i>Resend invitation / password link</button>
        </form>
    <?php endif; ?>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Manager already includes everything a Workflow Manager can open, so the two switches are either/or.
(function () {
    var manager = document.getElementById('is_manager'), workflow = document.getElementById('is_workflow');
    if (!manager || !workflow) { return; }
    manager.addEventListener('change', function () { if (manager.checked) { workflow.checked = false; } });
    workflow.addEventListener('change', function () { if (workflow.checked) { manager.checked = false; } });
})();
</script>
<?= $this->endSection() ?>
