<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<form class="card border-0 shadow-sm mb-3" method="get">
    <div class="card-body d-flex gap-2">
        <input class="form-control" name="action" value="<?= esc($action) ?>" placeholder="Filter by action prefix, e.g. user. or role.">
        <button class="btn btn-outline-primary">Filter</button>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Change</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (! $rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-5">Nothing logged yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="When" class="text-nowrap"><?= esc($r['created_at']) ?></td>
                    <td data-label="Who"><?= esc($r['actor'] ?? ($r['actor_id'] ? '#' . $r['actor_id'] : 'System')) ?></td>
                    <td data-label="Action"><code><?= esc($r['action']) ?></code></td>
                    <td data-label="Entity"><?= esc(trim(($r['entity'] ?? '') . ' ' . ($r['entity_id'] ?? ''))) ?></td>
                    <td data-label="Change" class="small text-break">
                        <?php if ($r['after']): ?><span class="text-body-secondary"><?= esc(mb_strimwidth($r['after'], 0, 140, '…')) ?></span><?php endif; ?>
                    </td>
                    <td data-label="IP" class="small"><?= esc($r['ip'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent"><?= $pager ?></div>
</div>
<?= $this->endSection() ?>
