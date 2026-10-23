<?php
$labels = ['pending' => ['Waiting / retrying', 'warning'], 'syncing' => ['Sending now', 'info'], 'synced' => ['Saved', 'success'], 'failed' => ['Failed: needs you', 'danger'], 'not_configured' => ['No connection yet', 'secondary']];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
    <h1 class="h3 mb-0">SharePoint sync</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= site_url('admin/sharepoint') ?>">Connections</a>
        <form method="post" action="<?= site_url('admin/sharepoint-sync/run') ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Sync now</button></form>
    </div>
</div>
<p class="text-body-secondary">
    Completed invoices wait here until they are saved to SharePoint. This also runs automatically every few minutes.
    A failed send is retried with growing delays (up to <?= (int) $max ?> attempts), then needs an administrator.
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="badge text-bg-light border text-decoration-none fs-6 fw-normal <?= $status === '' ? 'border-primary' : '' ?>" href="<?= site_url('admin/sharepoint-sync') ?>">All <strong><?= array_sum($counts) ?></strong></a>
    <?php foreach ($labels as $k => [$label, $colour]): ?>
        <a class="badge text-bg-<?= $colour ?> text-decoration-none fs-6 fw-normal <?= $status === $k ? 'border border-3 border-dark' : '' ?>" href="<?= site_url('admin/sharepoint-sync') . '?status=' . $k ?>"><?= esc($label) ?> <strong><?= (int) ($counts[$k] ?? 0) ?></strong></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>Invoice</th><th>Company</th><th>Status</th><th>Tries</th><th>Next try</th><th>Last error</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): [$label, $colour] = $labels[$r['status']] ?? [$r['status'], 'secondary']; ?>
                <tr>
                    <td data-label="Invoice"><a class="fw-semibold text-decoration-none" href="<?= site_url('invoices/' . $r['invoice_id']) ?>"><?= esc($r['invoice_number'] ?: '#' . $r['invoice_id']) ?></a>
                        <div class="small text-body-secondary"><?= esc($r['supplier'] ?: '') ?></div></td>
                    <td data-label="Company"><?= esc($r['company_code']) ?></td>
                    <td data-label="Status"><span class="badge text-bg-<?= $colour ?>"><?= esc($label) ?></span>
                        <?php if ($r['connection_name']): ?><div class="small text-body-secondary"><?= esc($r['connection_name']) ?></div><?php endif; ?></td>
                    <td data-label="Tries"><?= (int) $r['attempts'] ?></td>
                    <td data-label="Next try" class="small"><?= $r['status'] === 'pending' && $r['next_attempt_at'] ? esc($r['next_attempt_at']) : '—' ?></td>
                    <td data-label="Last error" class="small text-danger text-break"><?= esc($r['last_error'] ?: '') ?></td>
                    <td class="actions text-end">
                        <?php if (in_array($r['status'], ['failed', 'pending', 'not_configured'], true)): ?>
                            <form method="post" action="<?= site_url('admin/sharepoint-sync/' . $r['invoice_id'] . '/retry') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary" type="submit">Retry now</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-body-secondary py-4">Nothing here.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination flex-wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('admin/sharepoint-sync') . '?' . http_build_query(['status' => $status, 'page' => $p]) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
<?= $this->endSection() ?>
