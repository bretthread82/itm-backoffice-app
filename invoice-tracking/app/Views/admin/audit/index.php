<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">Audit log</h1>
<p class="text-body-secondary">Every administrative change, sign-in related event and file removal, newest first. Entries cannot be edited or deleted. <?= (int) $total ?> match.</p>

<form method="get" class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0" for="event">Event starts with</label>
        <input class="form-control" id="event" name="event" list="events" value="<?= esc($f['event']) ?>" placeholder="e.g. sharepoint.">
        <datalist id="events"><?php foreach ($events as $e): ?><option value="<?= esc($e) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0" for="user">Person</label>
        <select class="form-select" id="user" name="user">
            <option value="">Anyone</option>
            <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $f['user'] === (int) $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= esc($f['from']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= esc($f['to']) ?>"></div>
    <div class="col-12 col-md-2 d-flex align-items-end gap-2"><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-outline-secondary" href="<?= site_url('admin/audit') ?>">Clear</a></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>When</th><th>Who</th><th>Event</th><th>About</th><th>Details</th><th>From</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="When" class="small text-nowrap"><?= esc($r['created_at']) ?></td>
                    <td data-label="Who"><?= $r['user_id'] ? esc($r['display_name'] ?: $r['username'] ?: '#' . $r['user_id']) : '<span class="text-body-secondary">System</span>' ?></td>
                    <td data-label="Event"><code><?= esc($r['event']) ?></code></td>
                    <td data-label="About" class="small"><?= esc(trim(($r['entity'] ?? '') . ' ' . ($r['entity_id'] ?? ''))) ?></td>
                    <td data-label="Details" class="small text-break"><?= esc((string) $r['detail']) ?></td>
                    <td data-label="From" class="small"><?= esc((string) $r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">Nothing matches.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination flex-wrap">
        <?php foreach (array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn (int $p): bool => $p >= 1 && $p <= $pages)) as $p): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= site_url('admin/audit') . '?' . http_build_query(array_filter($f) + ['page' => $p]) ?>"><?= $p ?></a></li>
        <?php endforeach; ?>
    </ul></nav>
<?php endif; ?>
<?= $this->endSection() ?>
