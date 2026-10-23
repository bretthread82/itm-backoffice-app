<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
    <h1 class="h3 mb-0">Group-level activity</h1>
    <a class="btn btn-outline-primary" href="<?= site_url('admin/group-level-activity.csv') . '?' . http_build_query($f) ?>"><i class="bi bi-filetype-csv me-1"></i>Download CSV</a>
</div>
<p class="text-body-secondary">
    Every action a group-level user took on a step that was not theirs. Each one needed a reason and was reported to the named approver
    and the submitter at the time. <strong><?= (int) $total ?></strong> action<?= $total === 1 ? '' : 's' ?> match<?= $total === 1 ? 'es' : '' ?>.
</p>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select class="form-select" name="user" aria-label="Person">
            <option value="">Everyone</option>
            <?php foreach ($actors as $a): ?><option value="<?= $a['id'] ?>" <?= (int) ($f['user'] ?? 0) === $a['id'] ? 'selected' : '' ?>><?= esc($a['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="company" aria-label="Company">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (int) ($f['company'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><input class="form-control" type="date" name="from" value="<?= esc($f['from'] ?? '') ?>" aria-label="From date"></div>
    <div class="col-6 col-md-2"><input class="form-control" type="date" name="to" value="<?= esc($f['to'] ?? '') ?>" aria-label="To date"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit">Filter</button></div>
</form>

<?php if ($byUser !== []): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($byUser as $u): ?><span class="badge text-bg-light border fs-6 fw-normal"><?= esc($u['actor']) ?> <strong><?= (int) $u['n'] ?></strong></span><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-cards">
            <thead><tr><th>When</th><th>Who</th><th>Did</th><th>Invoice</th><th>Step / on behalf of</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="When" class="small text-nowrap"><?= esc($r['acted_at']) ?></td>
                    <td data-label="Who"><?= esc($r['actor']) ?></td>
                    <td data-label="Did"><span class="badge text-bg-danger"><?= esc($r['action']) ?></span></td>
                    <td data-label="Invoice"><a class="text-decoration-none" href="<?= site_url('invoices/' . $r['invoice_id']) ?>"><?= esc($r['invoice_number'] ?: '#' . $r['invoice_id']) ?></a>
                        <div class="small text-body-secondary"><?= esc($r['company_code']) ?> · <?= esc($r['supplier'] ?: '') ?> · <?= invoice_money($r['amount'], $r['currency']) ?></div></td>
                    <td data-label="Step" class="small"><?= esc($r['step_label'] ?: '—') ?><?= $r['on_behalf_name'] ? '<div class="text-body-secondary">for ' . esc($r['on_behalf_name']) . '</div>' : '' ?></td>
                    <td data-label="Reason" class="small"><?= $r['reason'] ? nl2br(esc($r['reason'])) : '<span class="text-body-secondary">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No group-level actions match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mb-4" aria-label="Pages"><ul class="pagination flex-wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('admin/group-level-activity') . '?' . http_build_query($f + ['page' => $p]) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<h2 class="h5">Changes to who has group-level access</h2>
<div class="card">
    <ul class="list-group list-group-flush small">
        <?php foreach ($changes as $c): ?>
            <li class="list-group-item">
                <span class="text-body-secondary"><?= esc($c['created_at']) ?></span>
                · <strong><?= esc($c['by_name'] ?? 'Someone') ?></strong>
                <?= $c['event'] === 'group_level.granted' ? 'gave' : 'removed' ?> group-level access
                <?= $c['event'] === 'group_level.granted' ? 'to' : 'from' ?> <strong><?= esc($c['target_name'] ?? 'a user') ?></strong>
            </li>
        <?php endforeach; ?>
        <?php if ($changes === []): ?><li class="list-group-item text-body-secondary">No changes recorded.</li><?php endif; ?>
    </ul>
</div>
<?= $this->endSection() ?>
