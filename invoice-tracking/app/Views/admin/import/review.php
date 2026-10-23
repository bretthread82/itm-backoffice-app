<?php
/**
 * @var array<string, mixed> $analysis
 * @var array<string, list<array<string, mixed>>> $levels
 */
$s       = $analysis['stats'];
$counts  = $analysis['people_counts'];
$statusLabel = [
    'matched_email' => ['Matched by email', 'success'], 'matched_name' => ['Matched by name', 'success'],
    'fill_email' => ['Email will be added to existing placeholder', 'primary'], 'new_with_email' => ['New user (email supplied)', 'primary'],
    'new_placeholder' => ['New placeholder (no email yet)', 'warning'], 'ambiguous' => ['Several matches: left unassigned', 'danger'],
];
$levelMeta = ['attention' => ['Needs attention', 'danger', 'bi-exclamation-octagon'], 'warning' => ['Fixed or worth a look', 'warning', 'bi-exclamation-triangle'], 'info' => ['For information', 'secondary', 'bi-info-circle']];
$blocked = $s['blocked'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">Review import</h1>
<p class="text-body-secondary mb-3"><?= esc($batch['filename']) ?> · nothing has been saved yet.</p>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Companies', $s['companies']], ['Routes', $s['routes']], ['Active routes', $s['active']],
        ['Archived (legacy)', $s['archived']], ['Inactive High Value variants', $s['variants']], ['Steps', $s['steps']],
    ] as [$label, $n]): ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="card card-body py-2"><div class="fs-4 fw-semibold"><?= (int) $n ?></div><div class="small text-body-secondary"><?= esc($label) ?></div></div></div>
    <?php endforeach; ?>
</div>

<?php if (! $analysis['has_emails']): ?>
    <div class="alert alert-warning">
        This file has <strong>no email column</strong>, so people will be created as placeholders (inactive, cannot sign in).
        Upload the updated list with emails later and the same accounts will be given their email addresses.
    </div>
<?php endif; ?>
<?php if ($blocked > 0): ?>
    <div class="alert alert-danger"><?= (int) $blocked ?> route(s) have conflicting steps and <strong>will not be imported</strong> until the source list is corrected (see "Needs attention").</div>
<?php endif; ?>

<?php foreach ($levelMeta as $level => [$title, $colour, $icon]): $items = $levels[$level]; if ($items === []) { continue; } ?>
    <div class="card mb-3 border-<?= $colour ?>-subtle">
        <div class="card-header bg-<?= $colour ?>-subtle d-flex justify-content-between align-items-center" role="button" data-bs-toggle="collapse" data-bs-target="#issues-<?= $level ?>" aria-expanded="<?= $level === 'attention' ? 'true' : 'false' ?>">
            <span><i class="bi <?= $icon ?> me-2"></i><strong><?= esc($title) ?></strong> <span class="badge text-bg-<?= $colour ?>"><?= count($items) ?></span></span>
            <i class="bi bi-chevron-down"></i>
        </div>
        <div id="issues-<?= $level ?>" class="collapse <?= $level === 'attention' ? 'show' : '' ?>">
            <ul class="list-group list-group-flush small">
                <?php foreach ($items as $i): ?>
                    <li class="list-group-item"><?= $i['line'] ? '<span class="text-body-secondary">Line ' . (int) $i['line'] . ':</span> ' : '' ?><?= esc($i['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endforeach; ?>

<div class="card mb-3">
    <div class="card-header"><strong>Routes</strong></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-cards">
            <thead><tr><th>Company</th><th>Invoice type</th><th>Steps</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($analysis['routes'] as $r): ?>
                <tr class="<?= $r['blocked'] ? 'table-danger' : '' ?>">
                    <td data-label="Company"><?= esc($r['company_name']) ?> <?= $r['company_exists'] ? '' : '<span class="badge text-bg-primary">new</span>' ?></td>
                    <td data-label="Type"><?= esc($r['type_name']) ?><?= $r['variant'] !== 'default' ? ' <span class="badge text-bg-info">High Value variant</span>' : '' ?></td>
                    <td data-label="Steps" class="small"><?= implode(' → ', array_map(static fn (array $st): string => esc($st['role']), $r['steps'])) ?></td>
                    <td data-label="Result">
                        <?php if ($r['blocked']): ?><span class="badge text-bg-danger">Blocked</span>
                        <?php elseif ($r['exists']): ?><span class="badge text-bg-secondary">Exists (skipped unless overwrite)</span>
                        <?php elseif ($r['archived']): ?><span class="badge text-bg-secondary">Archived (inactive)</span>
                        <?php elseif (! $r['active']): ?><span class="badge text-bg-secondary">Inactive until trigger set</span>
                        <?php else: ?><span class="badge text-bg-success">Active</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><strong>People</strong> <span class="text-body-secondary small">(<?= count($analysis['people']) ?>)</span></div>
            <div class="card-body pb-0">
                <?php foreach ($counts as $status => $n): ?>
                    <span class="badge text-bg-<?= $statusLabel[$status][1] ?? 'secondary' ?> me-1"><?= (int) $n ?> · <?= esc($statusLabel[$status][0] ?? $status) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="table-responsive" style="max-height: 22rem;">
                <table class="table table-sm mb-0 table-cards">
                    <thead><tr><th>Name</th><th>Email</th><th>Routes</th><th>What happens</th></tr></thead>
                    <tbody>
                    <?php foreach ($analysis['people'] as $p): ?>
                        <tr>
                            <td data-label="Name"><?= esc($p['name']) ?></td>
                            <td data-label="Email"><?= $p['email'] !== '' ? esc($p['email']) : '<span class="text-body-secondary">—</span>' ?></td>
                            <td data-label="Steps"><?= (int) $p['routes'] ?></td>
                            <td data-label="Result"><span class="badge text-bg-<?= $statusLabel[$p['status']][1] ?? 'secondary' ?>"><?= esc($statusLabel[$p['status']][0] ?? $p['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><strong>Teams and outside parties</strong></div>
            <div class="card-body small text-body-secondary pb-0">These are not people, so no user accounts are created. Shared mailboxes become teams (with no members yet): add the members and group mailbox under Admin > Teams.</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($analysis['shared'] as $sh): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= esc($sh['name']) ?></span>
                        <span><span class="badge text-bg-light border"><?= $sh['kind'] === 'external' ? 'Outside party' : 'Team' ?></span> <span class="text-body-secondary small"><?= (int) $sh['routes'] ?> steps</span></span>
                    </li>
                <?php endforeach; ?>
                <?php if ($analysis['shared'] === []): ?><li class="list-group-item text-body-secondary">None found.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card card-body">
    <form method="post" action="<?= site_url('admin/import/' . $batch['id'] . '/commit') ?>" class="d-flex flex-wrap gap-3 align-items-center">
        <?= csrf_field() ?>
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
            <label class="form-check-label" for="overwrite">Replace the steps of routes that already exist (their active status and trigger are kept)</label>
        </div>
        <div class="ms-auto d-flex gap-2">
            <button class="btn btn-primary" type="submit" onclick="return confirm('Import these routes now?')"><i class="bi bi-check2-circle me-1"></i>Import</button>
        </div>
    </form>
    <form method="post" action="<?= site_url('admin/import/' . $batch['id'] . '/discard') ?>" class="mt-2">
        <?= csrf_field() ?>
        <button class="btn btn-link text-danger p-0" type="submit">Discard this upload</button>
    </form>
</div>
<?= $this->endSection() ?>
