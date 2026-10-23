<?php
/**
 * Shared top of the long-term analytics pages: title, tabs, data-freshness notice and filters.
 *
 * @var string                      $tab       volume | cycle | backlog
 * @var string                      $csvPath   path of this page's CSV export
 * @var array<string, mixed>        $f         validated filters (AnalyticsService::normalise)
 * @var list<array<string, mixed>>  $companies
 * @var list<array<string, mixed>>  $groups
 * @var list<string>                $types
 * @var array<string, mixed>        $status    last_run, has_data, since
 * @var bool                        $error     the analytics database could not be read
 * @var bool                        $allowDay
 */

use App\Services\Analytics\AnalyticsService;

$tabPaths = ['volume' => 'admin/analytics', 'cycle' => 'admin/analytics/cycle-time', 'backlog' => 'admin/analytics/backlog'];
$query = array_filter($f, static fn ($v) => $v !== '' && $v !== 0);
?>
<?= $this->setData([
    'headerTitle'    => 'Reports & Analytics',
    'headerSubtitle' => 'Long-term trends across months and years, kept separately from the live invoice data',
    'headerActions'  => $error ? '' : '<a class="btn btn-outline-primary" href="' . esc(site_url($csvPath) . '?' . http_build_query($query), 'attr') . '"><i class="bi bi-filetype-csv me-1"></i>Download this view (CSV)</a>',
])->include('components/page_header') ?>

<?= $this->setData(['reportTab' => $tab, 'reportTabQuery' => $f])->include('components/report_tabs') ?>

<?php if ($error): ?>
    <div class="alert alert-warning" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>The analytics database could not be reached, so long-term trends are unavailable right now.
        Everything else in the app is unaffected. Ask an administrator to check the analytics database settings (see the deployment guide).
    </div>
<?php elseif (! $status['has_data']): ?>
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle me-1"></i>No analytics data has been loaded yet. An administrator needs to run <code>php spark analytics:backfill</code> once, then the nightly <code>analytics:load</code> keeps it current.
    </div>
<?php else: ?>
    <p class="small text-body-secondary mb-3">
        Data as of <strong><?= $status['last_run'] ? esc(date('j M Y H:i', strtotime((string) $status['last_run']))) : 'unknown' ?></strong>
        (refreshed nightly; today's activity appears tomorrow)<?= $status['since'] ? ', history from ' . esc(date('j M Y', strtotime((string) $status['since']))) : '' ?>.
        Amounts are shown per currency and never added across currencies.
    </p>
    <div class="card mb-3">
        <form method="get" class="filter-bar">
            <select class="form-select" name="company" aria-label="Company">
                <option value="">All my companies</option>
                <?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $f['company'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?> · <?= esc($c['name']) ?></option><?php endforeach; ?>
            </select>
            <select class="form-select" name="group" aria-label="Sub-group">
                <option value="">All sub-groups</option>
                <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $f['group'] === (int) $g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option><?php endforeach; ?>
            </select>
            <?php if ($tab !== 'backlog'): ?>
                <select class="form-select" name="type" aria-label="Invoice type">
                    <option value="">All invoice types</option>
                    <?php foreach ($types as $t): ?><option value="<?= esc($t, 'attr') ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option><?php endforeach; ?>
                </select>
                <input class="form-control" type="text" name="supplier" value="<?= esc($f['supplier'], 'attr') ?>" placeholder="Supplier code or name" aria-label="Supplier code or name">
            <?php endif; ?>
            <input class="form-control" type="date" name="from" value="<?= esc($f['from'], 'attr') ?>" aria-label="From date">
            <input class="form-control" type="date" name="to" value="<?= esc($f['to'], 'attr') ?>" aria-label="To date">
            <select class="form-select" name="gran" aria-label="Group by">
                <?php foreach (AnalyticsService::GRANULARITIES + ($allowDay ? ['day' => 'Day'] : []) as $k => $label): ?><option value="<?= $k ?>" <?= $f['gran'] === $k ? 'selected' : '' ?>>By <?= strtolower($label) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Apply</button>
            <a class="btn btn-link" href="<?= site_url($tabPaths[$tab]) ?>">Reset</a>
        </form>
    </div>
<?php endif; ?>
