<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->include('profile/_tabs') ?>

<form method="post" action="<?= site_url('profile/notifications') ?>" class="card border-0 shadow-sm">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Notification</th><th class="text-center">In app</th><th class="text-center">Email</th></tr></thead>
            <tbody>
            <?php foreach ($types as $type => $def): ?>
                <tr>
                    <td><?= esc($def['label']) ?><?php if (! empty($def['locked'])): ?> <span class="badge text-bg-light border" title="Security notices always appear in the app">Security</span><?php endif; ?></td>
                    <td class="text-center">
                        <input class="form-check-input" type="checkbox" name="pref[<?= esc($type, 'attr') ?>][in_app]" value="1"
                               aria-label="<?= esc($def['label'], 'attr') ?> in app"
                               <?= $prefs[$type]['in_app'] ? 'checked' : '' ?> <?= ! empty($def['locked']) ? 'disabled' : '' ?>>
                    </td>
                    <td class="text-center">
                        <input class="form-check-input" type="checkbox" name="pref[<?= esc($type, 'attr') ?>][email]" value="1"
                               aria-label="<?= esc($def['label'], 'attr') ?> by email" <?= $prefs[$type]['email'] ? 'checked' : '' ?>>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent p-3 text-end"><button class="btn btn-primary">Save preferences</button></div>
</form>
<?= $this->endSection() ?>
