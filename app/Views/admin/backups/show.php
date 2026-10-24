<?php
helper('number');

$chip = static fn (string $status): string => match ($status) {
    'success', 'ok' => '<span class="badge-status st-success"><i class="bi bi-check-circle me-1"></i>' . ($status === 'ok' ? 'Sent' : 'Success') . '</span>',
    'partial'       => '<span class="badge-status st-warning"><i class="bi bi-exclamation-triangle me-1"></i>Partly sent</span>',
    'failed'        => '<span class="badge-status st-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>',
    default         => '<span class="badge-status st-info"><i class="bi bi-hourglass-split me-1"></i>' . ($status === 'pending' ? 'Waiting' : 'Running') . '</span>',
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/backups') ?>"><i class="bi bi-arrow-left me-1"></i>All backups</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-sm-3">Result</dt><dd class="col-sm-9"><?= $chip($run['status']) ?></dd>
            <dt class="col-sm-3">Started</dt><dd class="col-sm-9"><?= esc(local_time($run['started_at'], 'j M Y, H:i:s')) ?> by <?= $run['trigger_type'] === 'manual' ? esc($run['started_by'] ?? 'a user') : 'the schedule' ?></dd>
            <dt class="col-sm-3">Finished</dt><dd class="col-sm-9"><?= $run['finished_at'] ? esc(local_time($run['finished_at'], 'j M Y, H:i:s')) : 'Not finished' ?></dd>
            <dt class="col-sm-3">File</dt><dd class="col-sm-9 text-break"><?= esc($run['file_name'] ?? '—') ?></dd>
            <dt class="col-sm-3">Size</dt><dd class="col-sm-9"><?= $run['size_bytes'] !== null ? esc(number_to_size((int) $run['size_bytes'])) : '—' ?></dd>
            <dt class="col-sm-3">Encrypted</dt><dd class="col-sm-9"><?= (int) $run['encrypted'] === 1 ? 'Yes (AES-256-GCM)' : 'No' ?></dd>
            <dt class="col-sm-3">SHA-256</dt><dd class="col-sm-9 text-break"><code><?= esc($run['sha256'] ?? '—') ?></code></dd>
            <?php if ($run['error']): ?><dt class="col-sm-3">Problem</dt><dd class="col-sm-9 text-danger text-break"><?= esc($run['error']) ?></dd><?php endif; ?>
        </dl>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body pb-0"><h2 class="h6 text-uppercase text-body-secondary mb-3">Where it was sent</h2></div>
    <div class="table-responsive">
        <table class="table itm-table table-cards align-middle mb-0">
            <thead><tr><th>Destination</th><th>Type</th><th>Result</th><th>Saved as</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if (! $targets): ?><tr><td colspan="5" class="text-body-secondary">No destination was used for this run<?= $run['status'] === 'failed' ? ' (the backup failed before sending).' : ': it only exists on the server.' ?></td></tr><?php endif; ?>
            <?php foreach ($targets as $t): ?>
                <tr>
                    <td data-label="Destination"><?= esc($t['name']) ?></td>
                    <td data-label="Type"><?= esc($types[$t['type']]['label'] ?? $t['type']) ?></td>
                    <td data-label="Result"><?= $chip($t['status']) ?></td>
                    <td data-label="Saved as" class="small text-break"><?= esc($t['remote_path'] ?? '—') ?></td>
                    <td data-label="Detail" class="small text-danger text-break"><?= esc($t['error'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
