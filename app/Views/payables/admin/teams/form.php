<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-xl-8">
    <h1 class="h3 mb-3"><?= esc($title) ?></h1>
    <form method="post" action="<?= site_url($id === null ? 'payables-admin/teams' : 'payables-admin/teams/' . $id) ?>" class="card card-body mb-3">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Team name</label>
                <input class="form-control" id="name" name="name" maxlength="120" required value="<?= esc(old('name', $team['name'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="description">Description <span class="text-body-secondary">(optional)</span></label>
                <input class="form-control" id="description" name="description" maxlength="255" value="<?= esc(old('description', $team['description'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="group_email">Group mailbox <span class="text-body-secondary">(optional)</span></label>
                <input class="form-control" type="email" id="group_email" name="group_email" maxlength="255" placeholder="finance@example.com" value="<?= esc(old('group_email', $team['group_email'] ?? '')) ?>">
                <div class="form-text">If the team has a shared mailbox, it is emailed as well as every member.</div>
            </div>
        </div>

        <h2 class="h6 mt-4">Members</h2>
        <p class="text-body-secondary small mb-2">Team members are told when an invoice reaches the team, and can accept it. Only members who belong to the invoice's company will see it. Removing someone returns anything they had accepted to the team.</p>
        <input class="form-control form-control-sm mb-2" type="search" id="member-filter" placeholder="Filter people…" aria-label="Filter people">
        <div class="border rounded p-2 inv-scroll-22"  id="member-list">
            <?php $selected = old('members') !== null ? array_map('intval', (array) old('members')) : $memberIds; ?>
            <?php foreach ($users as $u): ?>
                <div class="form-check member-row">
                    <input class="form-check-input" type="checkbox" name="members[]" id="m<?= (int) $u['id'] ?>" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selected, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="m<?= (int) $u['id'] ?>"><?= esc($u['name']) ?><?= $u['active'] ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></label>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($id !== null): ?>
            <div class="form-check form-switch mt-3">
                <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= ($team['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="active">Active<?= $routes > 0 ? ' <span class="text-body-secondary">(used on ' . (int) $routes . ' active route step(s); change those routes before switching it off)</span>' : '' ?></label>
            </div>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('payables-admin/teams') ?>">Back</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script {csp-script-nonce}>
(function () {
    var box = document.getElementById('member-filter');
    box.addEventListener('input', function () {
        var q = box.value.toLowerCase();
        document.querySelectorAll('#member-list .member-row').forEach(function (r) {
            r.hidden = q !== '' && r.textContent.toLowerCase().indexOf(q) === -1;
        });
    });
})();
</script>
<?= $this->endSection() ?>
