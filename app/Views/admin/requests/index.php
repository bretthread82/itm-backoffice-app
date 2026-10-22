<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<div class="btn-group" role="group" aria-label="Status">
    <?php foreach (['pending', 'approved', 'rejected'] as $s): ?>
        <a class="btn btn-outline-secondary <?= $status === $s ? 'active' : '' ?>" href="<?= site_url('admin/requests?status=' . $s) ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
    <?php if (! $rows): ?>
        <div class="card-body text-center text-body-secondary py-5">No <?= esc($status) ?> requests.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead><tr><th>Who</th><th>Change</th><th>Reason</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Who"><?= esc($r['display_name'] ?: $r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td data-label="Change">
                        <span class="fw-semibold"><?= esc($r['label']) ?></span>:
                        <span class="text-body-secondary"><?= esc($r['current']) ?></span>
                        <i class="bi bi-arrow-right mx-1"></i><?= esc($r['requested']) ?>
                    </td>
                    <td data-label="Reason" class="small"><?= esc($r['reason'] ?? '—') ?></td>
                    <td class="text-end">
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" action="<?= site_url('admin/requests/' . $r['id']) ?>" class="d-flex gap-2 justify-content-end">
                                <?= csrf_field() ?>
                                <input class="form-control form-control-sm" name="note" maxlength="500" placeholder="Note (optional)" aria-label="Decision note">
                                <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button>
                                <button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-body-secondary"><?= esc($r['decision_note'] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
