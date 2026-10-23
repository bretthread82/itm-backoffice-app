<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-3">Import: <?= esc($batch['filename']) ?></h1>
<p>Status: <span class="badge text-bg-<?= $batch['status'] === 'committed' ? 'success' : 'secondary' ?>"><?= esc(ucfirst($batch['status'])) ?></span>
    <?= $batch['committed_at'] ? ' on ' . esc($batch['committed_at']) : '' ?></p>

<?php if ($summary !== []): ?>
    <div class="card card-body mb-3">
        <div class="row g-3">
            <?php foreach ([
                'companies_created' => 'Companies created', 'types_created' => 'Invoice types created',
                'routes_created' => 'Routes created', 'routes_updated' => 'Routes updated',
                'routes_skipped_existing' => 'Existing routes skipped', 'routes_blocked' => 'Blocked routes (not imported)',
                'steps' => 'Steps written', 'placeholders_created' => 'Placeholder users (no email)',
                'users_created' => 'Users created (with email)', 'emails_filled' => 'Emails added to placeholders',
                'unassigned' => 'Steps left unassigned',
            ] as $k => $label): ?>
                <div class="col-6 col-md-4 col-xl-3"><div class="fs-4 fw-semibold"><?= (int) ($summary[$k] ?? 0) ?></div><div class="text-body-secondary small"><?= esc($label) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="d-flex gap-2">
    <a class="btn btn-primary" href="<?= site_url('admin/routes') ?>">View routes</a>
    <a class="btn btn-outline-secondary" href="<?= site_url('admin/import') ?>">Back to imports</a>
</div>
<?= $this->endSection() ?>
