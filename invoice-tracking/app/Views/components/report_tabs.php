<?php
/**
 * Tabs shared by every Reports & Analytics page: Outstanding & ageing, By supplier and (managers and
 * administrators only; the routes enforce it too) Long-term analysis. While on the analysis tab its three views
 * show as pills underneath.
 *
 * @var string               $reportTab       outstanding | suppliers | volume | cycle | backlog
 * @var array<string, mixed> $reportTabQuery  company / group carried from tab to tab (optional)
 */
$user       = auth()->user();
$canAnalyse = $user !== null && ((int) ($user->is_admin ?? 0) === 1 || (int) ($user->is_manager ?? 0) === 1);
$carry      = array_filter(array_intersect_key($reportTabQuery ?? [], ['company' => 1, 'group' => 1]), static fn ($v) => $v !== '' && $v !== null && $v !== 0);
$qs         = $carry === [] ? '' : '?' . http_build_query($carry);

$views = [
    'volume'  => ['admin/analytics', 'Volume & spend', 'bi-cash-coin'],
    'cycle'   => ['admin/analytics/cycle-time', 'Cycle time', 'bi-speedometer2'],
    'backlog' => ['admin/analytics/backlog', 'Backlog history', 'bi-layers'],
];
$inAnalysis = isset($views[$reportTab]);

$tabs = [
    'outstanding' => ['reports', 'Outstanding & ageing', 'bi-bar-chart-line', $reportTab === 'outstanding'],
    'suppliers'   => ['reports/suppliers', 'By supplier', 'bi-shop', $reportTab === 'suppliers'],
];
if ($canAnalyse) {
    $tabs['analysis'] = ['admin/analytics', 'Long-term analysis', 'bi-graph-up-arrow', $inAnalysis];
}
?>
<ul class="nav nav-tabs <?= $canAnalyse && $inAnalysis ? 'mb-2' : 'mb-3' ?>" aria-label="Reports and analysis">
    <?php foreach ($tabs as [$path, $label, $icon, $active]): ?>
        <li class="nav-item"><a class="nav-link <?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?> href="<?= esc(site_url($path) . $qs, 'attr') ?>"><i class="bi <?= $icon ?> me-1"></i><?= esc($label) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php if ($canAnalyse && $inAnalysis): ?>
    <ul class="nav nav-pills small mb-3" aria-label="Long-term analysis views">
        <?php foreach ($views as $key => [$path, $label, $icon]): ?>
            <li class="nav-item"><a class="nav-link py-1 <?= $key === $reportTab ? 'active' : '' ?>" <?= $key === $reportTab ? 'aria-current="page"' : '' ?> href="<?= esc(site_url($path) . $qs, 'attr') ?>"><i class="bi <?= $icon ?> me-1"></i><?= esc($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
