<?php
/**
 * Left navigation. Entries only appear when the route exists; system entries only for admins, workflow and user entries for managers too.
 *
 * @var bool $navIsAdmin    administrator: also sees the System entries
 * @var bool $navIsManager  manager or administrator: sees Administration and Workflow
 * @var array{waiting: int, returned: int, overdue: int} $navCounts
 */
$path  = trim(service('request')->getUri()->getPath(), '/');
$query = service('request')->getGet();
$qs    = static fn (array $q): string => 'invoices?' . http_build_query($q);

// Active state: exact page, plus the invoice filter shortcuts match on their query string.
$is = static function (string $href) use ($path, $query): bool {
    [$p, $q] = array_pad(explode('?', $href, 2), 2, '');
    if ($p === '') {
        return $path === '';
    }
    // The long-term analysis screens are tabs of Reports & Analytics.
    if ($p === 'reports' && str_starts_with($path, 'admin/analytics')) {
        return true;
    }
    if ($p === 'invoices') {
        parse_str($q, $want);
        if ($path !== 'invoices') {
            return false;
        }
        foreach ($want as $k => $v) {
            if (($query[$k] ?? null) !== $v) {
                return false;
            }
        }
        // "All invoices" is only active when no shortcut filter is applied.
        return $want !== [] || (($query['status'] ?? '') === '' && ($query['age'] ?? '') === '');
    }

    return $path === $p || str_starts_with($path, $p . '/');
};

$sections = [
    [null, [
        ['', 'bi-grid-1x2-fill', 'Dashboard', 0, ''],
        ['approvals', 'bi-check2-square', 'My Tasks', $navCounts['waiting'], 'danger'],
    ]],
    ['Invoices', [
        [$qs([]), 'bi-file-earmark-text', 'All Invoices', 0, ''],
        [$qs(['status' => 'open']), 'bi-hourglass-split', 'Pending Approval', 0, ''],
        [$qs(['status' => 'returned']), 'bi-exclamation-circle', 'Returned', $navCounts['returned'], 'warn'],
        [$qs(['status' => 'outstanding', 'age' => 'overdue']), 'bi-clock-history', 'Overdue', $navCounts['overdue'], 'danger'],
        [$qs(['status' => 'processed']), 'bi-gear', 'Processed', 0, ''],
        [$qs(['status' => 'paid']), 'bi-check-circle', 'Paid', 0, ''],
        [$qs(['status' => 'draft']), 'bi-pencil-square', 'Drafts', 0, ''],
    ]],
    ['Operations', [
        ['reports', 'bi-bar-chart-line', 'Reports & Analytics', 0, ''],
        ['account/delegations', 'bi-people', 'My Delegations', 0, ''],
        ['account/api-tokens', 'bi-key', 'API Tokens', 0, ''],
        ['help', 'bi-question-circle', 'Help & Manual', 0, ''],
    ]],
];
if ($navIsManager) {
    $adminItems = [
        ['admin/users', 'bi-person-gear', 'Users', 0, ''],
        ['admin/teams', 'bi-people-fill', 'Teams', 0, ''],
        ['admin/companies', 'bi-building', 'Companies', 0, ''],
        ['admin/groups', 'bi-diagram-3', 'Sub-groups', 0, ''],
    ];
    if ($navIsAdmin) {
        $adminItems[] = ['admin/group-level', 'bi-shield-lock', 'Group-level Users', 0, ''];
    }
    $adminItems[] = ['admin/group-level-activity', 'bi-clipboard-data', 'Group-level Activity', 0, ''];
    if ($navIsAdmin) {
        $adminItems[] = ['admin/api-tokens', 'bi-key-fill', 'API Tokens (all)', 0, ''];
    }
    $sections[] = ['Administration', $adminItems];
    $sections[] = ['Workflow', [
        ['admin/default-invoice-types', 'bi-tags', 'Invoice Types', 0, ''],
        ['admin/suppliers', 'bi-shop', 'Suppliers', 0, ''],
        ['admin/routes', 'bi-signpost-split', 'Approval Routes', 0, ''],
        ['admin/route-variants', 'bi-diagram-2', 'Route Variants', 0, ''],
        ['admin/import', 'bi-cloud-upload', 'Import Routing', 0, ''],
        ['admin/delegations', 'bi-person-lines-fill', 'Delegations', 0, ''],
    ]];
    $system = [];
    if ($navIsAdmin) {
        $system[] = ['admin/sharepoint', 'bi-cloud', 'SharePoint Connections', 0, ''];
        $system[] = ['admin/sharepoint-sync', 'bi-arrow-repeat', 'SharePoint Sync', 0, ''];
    }
    $system[] = ['admin/audit', 'bi-journal-text', 'Audit Log', 0, ''];
    if ($navIsAdmin) {
        $system[] = ['admin/settings', 'bi-sliders', 'System Settings', 0, ''];
    }
    $sections[] = ['System', $system];
} elseif (! empty($navIsWorkflow)) {
    // Workflow Manager: the four workflow settings screens and nothing else.
    $sections[] = ['Workflow', [
        ['admin/default-invoice-types', 'bi-tags', 'Invoice Types', 0, ''],
        ['admin/suppliers', 'bi-shop', 'Suppliers', 0, ''],
        ['admin/routes', 'bi-signpost-split', 'Approval Routes', 0, ''],
        ['admin/route-variants', 'bi-diagram-2', 'Route Variants', 0, ''],
    ]];
}
?>
<?php $appVersion = \App\Services\AppVersion::load(); ?>
<aside class="mg-sidebar" id="sidebar" aria-label="Main navigation">
    <a class="mg-brand" href="<?= site_url('/') ?>">
        <span class="mg-brand-mark">M</span>
        <span class="mg-brand-text">MALONEY GROUP<small>OF COMPANIES</small></span>
    </a>
    <?php foreach ($sections as [$heading, $items]): ?>
        <?php if ($heading !== null): ?><div class="mg-nav-section"><?= esc($heading) ?></div><?php endif; ?>
        <ul class="mg-nav">
            <?php foreach ($items as [$href, $icon, $label, $n, $tone]): ?>
                <li><a class="<?= $is($href) ? 'active' : '' ?>" href="<?= site_url($href) ?>">
                    <i class="bi <?= $icon ?>"></i><?= esc($label) ?>
                    <?php if ($n > 0): ?><span class="mg-count <?= $tone === 'warn' ? 'warn' : '' ?>"><?= (int) $n ?></span><?php endif; ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
    <div class="mg-sidebar-foot">People &middot; Progress &middot; Opportunity
        <div class="mg-version" title="Build <?= (int) $appVersion->build ?>">v<?= esc($appVersion->version()) ?> &middot; build <?= (int) $appVersion->build ?></div>
    </div>
</aside>
