<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h3 mb-0">Teams</h1>
    <a class="btn btn-primary" href="<?= site_url('payables-admin/teams/new') ?>"><i class="bi bi-plus-lg me-1"></i>New team</a>
</div>
<p class="text-body-secondary">A team can be assigned to any step on a route. Everyone on the team is told, and so is its group mailbox if it has one; on approve and process steps one member accepts the invoice and then acts on it. Joining a team does not give anyone access to a company's invoices: they still need to be a member of that company.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>Team</th><th>Group mailbox</th><th>Members</th><th>Used on active routes</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td data-label="Team"><span class="fw-semibold"><?= esc($t['name']) ?></span><?= $t['description'] ? '<div class="small text-body-secondary">' . esc($t['description']) . '</div>' : '' ?></td>
                    <td data-label="Group mailbox"><?= $t['group_email'] ? esc($t['group_email']) : '<span class="text-body-secondary">—</span>' ?></td>
                    <td data-label="Members"><?= (int) $t['member_count'] ?><?= (int) $t['member_count'] === 0 && (int) $t['route_count'] > 0 ? ' <span class="badge text-bg-danger">No active members</span>' : '' ?></td>
                    <td data-label="Used on active routes"><?= (int) $t['route_count'] ?></td>
                    <td data-label="Status"><?= $t['active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('payables-admin/teams/' . $t['id'] . '/edit') ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($teams === []): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No teams yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
