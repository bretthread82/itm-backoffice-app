<?php
helper('number');

$chip = static fn (string $status): string => match ($status) {
    'success' => '<span class="badge-status st-success"><i class="bi bi-check-circle me-1"></i>Success</span>',
    'partial' => '<span class="badge-status st-warning"><i class="bi bi-exclamation-triangle me-1"></i>Partly sent</span>',
    'failed'  => '<span class="badge-status st-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>',
    default   => '<span class="badge-status st-info"><i class="bi bi-hourglass-split me-1"></i>Running</span>',
};
$canManage = can('backups.manage');
$ago       = static fn (?string $utc): string => $utc ? local_time($utc, 'j M Y, H:i') : 'Never';
$lastTone  = $lastGood === null ? 'danger' : ($stale ? 'warning' : 'success');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<?php if ($canManage): ?>
    <form method="post" action="<?= site_url('admin/backups/run') ?>" data-confirm="Start a backup now?"><?= csrf_field() ?>
        <button class="btn btn-primary" <?= $running ? 'disabled' : '' ?>><i class="bi bi-cloud-arrow-up me-1"></i><?= $running ? 'Backup running…' : 'Run now' ?></button>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($stale): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>
        <?= $lastGood === null ? 'No successful backup has been made yet.' : 'The last successful backup is more than ' . (int) $staleHours . ' hours old.' ?>
        Check that the cron job is running (<code>php spark backup:run</code>).
    </div>
<?php endif; ?>
<?php if (! $encryption): ?>
    <div class="alert alert-danger"><i class="bi bi-shield-exclamation me-1"></i>No backup passphrase is set, so backups cannot be encrypted. Add <code>backups.passphrase</code> to <code>.env</code> (at least 12 characters) and keep a copy somewhere safe off this server.</div>
<?php endif; ?>
<?php if ($canManage && $health['enabled'] === 0): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No destination is switched on, so backups only exist on this server. Add one below.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card stat-card tone-<?= $lastTone ?>">
            <span class="stat-icon"><i class="bi bi-shield-check"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">Last good backup</span>
                <span class="stat-value d-block fs-6"><?= esc($ago($lastGood['finished_at'] ?? null)) ?></span>
                <span class="stat-sub d-block"><?= $lastGood ? esc(number_to_size((int) $lastGood['size_bytes'])) : 'Nothing yet' ?></span>
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card tone-<?= $last === null ? 'draft' : ($last['status'] === 'success' ? 'success' : ($last['status'] === 'running' ? 'info' : 'danger')) ?>">
            <span class="stat-icon"><i class="bi bi-clock-history"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">Latest run</span>
                <span class="stat-value d-block fs-6"><?= $last ? $chip($last['status']) : 'No runs' ?></span>
                <span class="stat-sub d-block"><?= esc($ago($last['started_at'] ?? null)) ?></span>
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card tone-<?= $health['failing'] > 0 ? 'danger' : ($health['enabled'] > 0 ? 'success' : 'warning') ?>">
            <span class="stat-icon"><i class="bi bi-cloud-check"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">Destinations on</span>
                <span class="stat-value d-block"><?= (int) $health['enabled'] ?></span>
                <span class="stat-sub d-block"><?= $health['failing'] > 0 ? (int) $health['failing'] . ' failing last test' : 'of ' . (int) $health['total'] . ' set up' ?></span>
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card tone-<?= $encryption ? 'success' : 'danger' ?>">
            <span class="stat-icon"><i class="bi bi-lock"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">Encryption</span>
                <span class="stat-value d-block fs-6"><?= $encryption ? 'On (AES-256)' : 'Off' ?></span>
                <span class="stat-sub d-block">Passphrase in .env</span>
            </span>
        </div>
    </div>
</div>

<?php if ($canManage): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h6 text-uppercase text-body-secondary mb-0">Destinations</h2>
            <a class="btn btn-outline-primary btn-sm" href="<?= site_url('admin/backups/destinations/new') ?>"><i class="bi bi-plus-lg me-1"></i>Add destination</a>
        </div>
        <div class="table-responsive">
            <table class="table itm-table table-cards align-middle mb-0">
                <thead><tr><th>Name</th><th>Type</th><th>Keeps</th><th>Status</th><th>Last test</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (! $destinations): ?><tr><td colspan="6" class="text-body-secondary">No destinations yet.</td></tr><?php endif; ?>
                <?php foreach ($destinations as $d): ?>
                    <tr>
                        <td data-label="Name"><?= esc($d['name']) ?></td>
                        <td data-label="Type"><i class="bi bi-<?= esc($types[$d['type']]['icon'] ?? 'cloud') ?> me-1"></i><?= esc($types[$d['type']]['label'] ?? $d['type']) ?></td>
                        <td data-label="Keeps"><?= (int) $d['keep_last'] ?> backups</td>
                        <td data-label="Status"><?= (int) $d['enabled'] === 1 ? '<span class="badge-status st-success">On</span>' : '<span class="badge-status st-draft">Off</span>' ?></td>
                        <td data-label="Last test" class="small">
                            <?php if ($d['last_test_ok'] === null): ?><span class="text-body-secondary">Not tested</span>
                            <?php elseif ((int) $d['last_test_ok'] === 1): ?><span class="badge-status st-success"><i class="bi bi-check-circle me-1"></i>Worked</span> <span class="text-body-secondary"><?= esc(local_time($d['last_test_at'], 'j M, H:i')) ?></span>
                            <?php else: ?><span class="badge-status st-danger"><i class="bi bi-x-circle me-1"></i>Failed</span> <span class="text-danger"><?= esc($d['last_test_error']) ?></span><?php endif; ?>
                        </td>
                        <td data-label="Actions" class="text-end text-nowrap">
                            <form class="d-inline" method="post" action="<?= site_url('admin/backups/destinations/' . (int) $d['id'] . '/test') ?>"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-primary" aria-label="Test <?= esc($d['name'], 'attr') ?>"><i class="bi bi-plug"></i> Test</button>
                            </form>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/backups/destinations/' . (int) $d['id'] . '/edit') ?>" aria-label="Edit <?= esc($d['name'], 'attr') ?>"><i class="bi bi-pencil"></i></a>
                            <form class="d-inline" method="post" action="<?= site_url('admin/backups/destinations/' . (int) $d['id'] . '/delete') ?>" data-confirm="Remove this destination? Files already sent there stay where they are."><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" aria-label="Remove <?= esc($d['name'], 'attr') ?>"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body pb-0"><h2 class="h6 text-uppercase text-body-secondary mb-3">Backup runs</h2></div>
    <div class="table-responsive">
        <table class="table itm-table table-cards align-middle mb-0">
            <thead><tr><th>Started</th><th>Result</th><th>Started by</th><th>File</th><th>Size</th><th>Took</th><th></th></tr></thead>
            <tbody>
            <?php if (! $runs): ?><tr><td colspan="7" class="text-center text-body-secondary py-5">No backups have run yet.</td></tr><?php endif; ?>
            <?php foreach ($runs as $r):
                $took = $r['finished_at'] ? max(0, strtotime($r['finished_at'] . ' UTC') - strtotime($r['started_at'] . ' UTC')) : null;
            ?>
                <tr>
                    <td data-label="Started" class="text-nowrap"><?= esc(local_time($r['started_at'], 'j M Y, H:i')) ?></td>
                    <td data-label="Result"><?= $chip($r['status']) ?></td>
                    <td data-label="Started by"><?= $r['trigger_type'] === 'manual' ? esc($r['started_by'] ?? 'A user') : 'Schedule' ?></td>
                    <td data-label="File" class="small text-break"><?= esc($r['file_name'] ?? '—') ?></td>
                    <td data-label="Size" class="text-nowrap"><?= $r['size_bytes'] !== null ? esc(number_to_size((int) $r['size_bytes'])) : '—' ?></td>
                    <td data-label="Took" class="text-nowrap"><?= $took === null ? '—' : ($took >= 60 ? intdiv($took, 60) . ' min ' . ($took % 60) . ' s' : $took . ' s') ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/backups/runs/' . (int) $r['id']) ?>">Details</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent"><?= $pager ?></div>
</div>
<?= $this->endSection() ?>
