<?php
$chip = static fn (string $state): string => match ($state) {
    'ok'      => '<span class="badge-status st-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>On time</span>',
    'running' => '<span class="badge-status st-info"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Running</span>',
    'failed'  => '<span class="badge-status st-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Failed</span>',
    'late'    => '<span class="badge-status st-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Late</span>',
    'stuck'   => '<span class="badge-status st-danger"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Stuck</span>',
    default   => '<span class="badge-status st-draft"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No runs yet</span>',
};
$runChip = static fn (string $status): string => match ($status) {
    'ok'     => $chip('ok'),
    'failed' => $chip('failed'),
    default  => $chip('running'),
};
$when = static fn (?string $utc): string => $utc ? local_time($utc, 'j M Y, H:i') : 'Never';
$took = static function (?string $from, ?string $to): string {
    if (! $from || ! $to) {
        return '—';
    }

    $s = max(0, strtotime($to . ' UTC') - strtotime($from . ' UTC'));

    return $s >= 60 ? intdiv($s, 60) . ' min ' . ($s % 60) . ' s' : $s . ' s';
};
$onTime = count(array_filter($jobs, static fn ($j) => in_array($j['state'], ['ok', 'running'], true)));
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php if ($stopped): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>
        <strong>Cron may not be running.</strong> The hourly <code>cron:check</code> has not reported in on time. Check that the cron service is running and that the lines under Cron setup below are installed.
        If cron has stopped completely, no alert can be sent, so look at this page when in doubt.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-4">
        <div class="card stat-card tone-<?= $attention > 0 ? 'danger' : 'success' ?>">
            <span class="stat-icon"><i class="bi bi-<?= $attention > 0 ? 'exclamation-triangle' : 'check2-circle' ?>" aria-hidden="true"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">Need attention</span>
                <span class="stat-value d-block"><?= (int) $attention ?></span>
                <span class="stat-sub d-block">of <?= count($jobs) ?> job(s)</span>
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="card stat-card tone-info">
            <span class="stat-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">On time</span>
                <span class="stat-value d-block"><?= (int) $onTime ?></span>
                <span class="stat-sub d-block">running to schedule</span>
            </span>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card stat-card tone-<?= $stopped ? 'warning' : 'draft' ?>">
            <span class="stat-icon"><i class="bi bi-heart-pulse" aria-hidden="true"></i></span>
            <span class="min-w-0">
                <span class="stat-label d-block">Last hourly check</span>
                <span class="stat-value d-block fs-6"><?= esc($when($lastCheck)) ?></span>
                <span class="stat-sub d-block">cron:check</span>
            </span>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body pb-0">
        <h2 class="h6 text-uppercase text-body-secondary mb-1">Scheduled jobs</h2>
        <p class="small text-body-secondary">Each job reports when it starts and ends. "Late" means it has not reported within its schedule plus a short allowance. This page cannot see the server's crontab itself.</p>
    </div>
    <div class="table-responsive">
        <table class="table itm-table table-cards align-middle mb-0">
            <thead><tr><th>Job</th><th>Schedule</th><th>Status</th><th>Last run</th><th>Took</th><th>Due by</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td data-label="Job"><?= esc($j['label']) ?><div class="small text-body-secondary"><code><?= esc($j['command']) ?></code></div></td>
                    <td data-label="Schedule" class="text-nowrap"><?= esc($j['cadence']) ?></td>
                    <td data-label="Status"><?= $chip($j['state']) ?></td>
                    <td data-label="Last run" class="text-nowrap"><?= esc($when($j['last']['started_at'] ?? null)) ?></td>
                    <td data-label="Took" class="text-nowrap"><?= esc($took($j['last']['started_at'] ?? null, $j['last']['finished_at'] ?? null)) ?></td>
                    <td data-label="Due by" class="text-nowrap"><?= $j['dueBy'] ? esc(local_time($j['dueBy'], 'j M, H:i')) : '—' ?></td>
                    <td data-label="Detail" class="small text-break"><?= esc($j['last']['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body pb-0">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h6 text-uppercase text-body-secondary mb-0">Recent runs</h2>
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="form-label small mb-0" for="command">Job</label>
                <select class="form-select form-select-sm w-auto" id="command" name="command" data-autosubmit>
                    <option value="">All jobs</option>
                    <?php foreach ($commands as $c): ?><option value="<?= esc($c, 'attr') ?>" <?= $c === $command ? 'selected' : '' ?>><?= esc($c) ?></option><?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table itm-table table-cards align-middle mb-0">
            <thead><tr><th>Started</th><th>Command</th><th>Result</th><th>Took</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if (! $runs): ?><tr><td colspan="5" class="text-center text-body-secondary py-5">No runs recorded yet. Jobs appear here the first time cron runs them.</td></tr><?php endif; ?>
            <?php foreach ($runs as $r): ?>
                <tr>
                    <td data-label="Started" class="text-nowrap"><?= esc($when($r['started_at'])) ?></td>
                    <td data-label="Command"><code><?= esc($r['command']) ?></code></td>
                    <td data-label="Result"><?= $runChip($r['status']) ?></td>
                    <td data-label="Took" class="text-nowrap"><?= esc($took($r['started_at'], $r['finished_at'])) ?></td>
                    <td data-label="Detail" class="small text-break"><?= esc($r['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent small text-body-secondary d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>History is kept for <?= (int) $keepDays ?> days.</span><?= $pager ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h6 text-uppercase text-body-secondary mb-1">Cron setup</h2>
        <p class="small text-body-secondary">Lines for the server's crontab (for example <code>/etc/cron.d/itm-backoffice</code>), run as the web user. The times are suggestions; if you change how often a job runs, update its schedule in the module registry so "Late" stays accurate.</p>
        <pre class="small mb-0 user-select-all text-break"><?php foreach ($jobs as $j): ?><?= esc($j['example'] . ' www-data cd ' . $root . ' && php spark ' . $j['command'] . ' >> writable/logs/' . str_replace(':', '-', $j['command']) . '.log 2>&1') . "\n" ?><?php endforeach; ?></pre>
    </div>
</div>
<?= $this->endSection() ?>
