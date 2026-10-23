<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Group-level users see every company. It is a separate boundary, not a role: an administrator is not automatically group-level.</div>
<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table itm-table table-cards">
                    <thead><tr><th>Name</th><th>Granted</th><th></th></tr></thead>
                    <tbody>
                    <?php if (! $granted): ?>
                        <tr><td colspan="3" class="text-center text-body-secondary py-4">No group-level users.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($granted as $g): ?>
                        <tr>
                            <td data-label="Name"><?= esc(($g['display_name'] ?? '') ?: trim(($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? '')) ?: 'User #' . $g['user_id']) ?></td>
                            <td data-label="Granted"><?= esc(local_time((string) $g['granted_at'], 'Y-m-d H:i')) ?></td>
                            <td class="actions">
                                <form method="post" action="<?= site_url('admin/group-level/' . $g['user_id'] . '/remove') ?>" data-confirm="Remove group-level access?">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <form method="post" action="<?= site_url('admin/group-level') ?>" class="card card-body">
            <?= csrf_field() ?>
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Grant access</h2>
            <div class="mb-3">
                <label class="form-label" for="user_id">User</label>
                <select class="form-select" id="user_id" name="user_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($candidates as $u): ?>
                        <option value="<?= $u['user_id'] ?>"><?= esc(($u['display_name'] ?? '') ?: $u['first_name'] . ' ' . $u['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button class="btn btn-primary">Grant</button></div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
