<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<p class="text-body-secondary small">Platform <?= esc($platform) ?>. Versions are major.minor.bug.</p>
<div class="card">
    <div class="table-responsive">
        <table class="table itm-table table-cards">
            <thead><tr><th>Module</th><th>Version</th><th>Description</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($modules as $m): ?>
                <tr>
                    <td data-label="Module" class="fw-semibold"><?= esc($m['title']) ?></td>
                    <td data-label="Version"><?= esc($m['version']) ?></td>
                    <td data-label="Description" class="text-body-secondary"><?= esc($m['description'] ?: ($m['optional'] ? '' : 'Part of the platform core.')) ?></td>
                    <td data-label="Status">
                        <?php if (! $m['optional']): ?>
                            <span class="badge-status st-info">Core</span>
                        <?php else: ?>
                            <span class="badge-status st-<?= $m['enabled'] ? 'success' : 'draft' ?>"><?= $m['enabled'] ? 'Enabled' : 'Disabled' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if ($m['optional']): ?>
                            <form method="post" action="<?= site_url('admin/modules/' . $m['key']) ?>"
                                  <?= $m['enabled'] ? 'data-confirm="Turn this module off? It disappears for everyone; its data is kept."' : '' ?>>
                                <?= csrf_field() ?>
                                <input type="hidden" name="enabled" value="<?= $m['enabled'] ? '0' : '1' ?>">
                                <button class="btn btn-sm <?= $m['enabled'] ? 'btn-outline-danger' : 'btn-primary' ?>"><?= $m['enabled'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                        <?php else: ?>
                            <span class="text-body-secondary small">Always on</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
