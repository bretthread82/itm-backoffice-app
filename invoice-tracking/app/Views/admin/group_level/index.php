<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">Group-level users</h1>
<p class="text-body-secondary">
    Members can <strong>view and approve invoices for every company</strong>, including companies added later.
    Everything they do is recorded against their own name and flagged as a group-level action.
    Only administrators can change this list.
</p>

<form method="post" action="<?= site_url('admin/group-level') ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label" for="user_id">Add a user</label>
            <select class="form-select" id="user_id" name="user_id" required>
                <option value="">— choose —</option>
                <?php foreach ($candidates as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= esc($u['display_name'] ?: $u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-grid"><button class="btn btn-primary" type="submit">Grant group-level access</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>User</th><th>Granted</th><th>By</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td data-label="User"><?= esc($m['display_name'] ?: $m['username']) ?><?= $m['active'] ? '' : ' <span class="badge text-bg-secondary">Disabled</span>' ?></td>
                    <td data-label="Granted"><?= esc($m['granted_at'] ?? '—') ?></td>
                    <td data-label="By"><?= esc($m['granted_by_name'] ?? '—') ?></td>
                    <td class="actions text-end">
                        <form method="post" action="<?= site_url('admin/group-level/' . $m['id'] . '/remove') ?>" onsubmit="return confirm('Remove group-level access?')">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($members === []): ?>
                <tr><td colspan="4" class="text-center text-body-secondary py-4">No group-level users yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
