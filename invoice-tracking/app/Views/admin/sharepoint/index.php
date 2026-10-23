<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
    <h1 class="h3 mb-0">SharePoint connections</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('admin/sharepoint-sync') ?>"><i class="bi bi-arrow-repeat me-1"></i>Sync queue</a>
        <a class="btn btn-primary" href="<?= site_url('admin/sharepoint/new') ?>"><i class="bi bi-plus-lg me-1"></i>New connection</a>
    </div>
</div>
<p class="text-body-secondary">
    Where completed invoices are saved (the PDF and a list item). Each company uses the connection chosen on its page, or the default one.
    Until a connection is switched on, finished invoices are kept safely and wait; nothing is sent.
</p>

<?php if ($noConnection > 0 && ! array_filter($connections, static fn (array $c): bool => (int) $c['is_default'] === 1)): ?>
    <div class="alert alert-info small"><?= (int) $noConnection ?> active compan<?= $noConnection === 1 ? 'y has' : 'ies have' ?> no connection and there is no default one, so their finished invoices will wait.</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>Name</th><th>Site</th><th>Companies</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($connections as $c): ?>
                <tr>
                    <td data-label="Name" class="fw-semibold"><?= esc($c['name']) ?><?= $c['is_default'] ? ' <span class="badge text-bg-primary">Default</span>' : '' ?></td>
                    <td data-label="Site" class="small text-break"><?= esc($c['site_url'] ?: '—') ?></td>
                    <td data-label="Companies"><?= (int) $c['company_count'] ?></td>
                    <td data-label="Status">
                        <?php if ((int) $c['enabled'] === 1): ?><span class="badge text-bg-success">On</span>
                        <?php elseif ($c['missing'] !== []): ?><span class="badge text-bg-secondary" title="Missing: <?= esc(implode(', ', $c['missing'])) ?>">Placeholder</span>
                        <?php else: ?><span class="badge text-bg-warning">Off</span><?php endif; ?>
                        <?php if ($c['last_error']): ?><span class="badge text-bg-danger" title="<?= esc($c['last_error']) ?>">Last error</span>
                        <?php elseif ($c['last_tested_at']): ?><span class="badge text-bg-light border">Tested <?= esc(time_ago($c['last_tested_at'])) ?></span><?php endif; ?>
                    </td>
                    <td class="actions text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/sharepoint/' . $c['id'] . '/edit') ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($connections === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">No connections yet. They can be set up now and switched on later.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
