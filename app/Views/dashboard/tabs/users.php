<?php
/**
 * @var array<string, mixed> $tab pending, total
 */
$labels = ['job_title' => 'Job title', 'department_id' => 'Department', 'manager_id' => 'Manager'];
?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span>Profile change requests waiting for a decision</span>
        <a class="small text-decoration-none" href="<?= site_url('admin/requests') ?>">Open all</a>
    </div>
    <div class="card-body">
        <?php if (! $tab['pending']): ?>
            <p class="text-body-secondary mb-0">No requests waiting.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table itm-table table-cards">
                    <thead><tr><th>Person</th><th>Change</th><th>Reason</th><th>Requested</th></tr></thead>
                    <tbody>
                    <?php foreach ($tab['pending'] as $r): ?>
                        <tr>
                            <td data-label="Person"><a class="fw-semibold text-decoration-none" href="<?= site_url('admin/requests') ?>"><?= esc($r['display_name']) ?></a></td>
                            <td data-label="Change"><?= esc($labels[$r['field']] ?? $r['field']) ?></td>
                            <td data-label="Reason"><?= esc($r['reason'] ?: '—') ?></td>
                            <td data-label="Requested"><?= esc(local_time($r['created_at'], 'Y-m-d H:i')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($tab['total'] > count($tab['pending'])): ?>
                <p class="small text-body-secondary mb-0"><?= (int) $tab['total'] - count($tab['pending']) ?> more on the requests page.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
