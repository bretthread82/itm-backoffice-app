<?php
/**
 * Shared by "My delegations" and Admin > Delegations.
 *
 * @var list<array<string, mixed>> $delegations
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $companies
 * @var bool $admin
 */
$now = date('Y-m-d H:i:s');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1"><?= $admin ? 'Delegations' : 'My delegations' ?></h1>
<p class="text-body-secondary">
    Hand your approvals to a colleague while you are away. They can approve, return or reject on your steps during the period you choose,
    and every action is recorded as theirs, "on behalf of" you. A delegate only works in companies they can already see.
</p>

<form method="post" action="<?= site_url($action) ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php if ($admin): ?>
            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="delegator_id">Delegating person</label>
                <select class="form-select" id="delegator_id" name="delegator_id" required>
                    <option value="">— choose —</option>
                    <?php foreach ($allUsers as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="col-md-6 col-xl-3">
            <label class="form-label" for="delegate_id">Delegate to</label>
            <select class="form-select" id="delegate_id" name="delegate_id" required>
                <option value="">— choose —</option>
                <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 col-xl-3">
            <label class="form-label" for="company_id">Company</label>
            <select class="form-select" id="company_id" name="company_id">
                <option value="">All companies</option>
                <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>"><?= esc($c['code']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-xl-3">
            <label class="form-label" for="starts_at">From</label>
            <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="<?= esc(old('starts_at', date('Y-m-d\TH:i'))) ?>" required>
        </div>
        <div class="col-6 col-xl-3">
            <label class="form-label" for="ends_at">Until</label>
            <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" value="<?= esc(old('ends_at', date('Y-m-d\TH:i', time() + 7 * 86400))) ?>" required>
        </div>
        <div class="col-xl-6">
            <label class="form-label" for="reason">Reason <span class="text-body-secondary">(optional)</span></label>
            <input class="form-control" id="reason" name="reason" maxlength="255" value="<?= esc(old('reason')) ?>">
        </div>
        <div class="col-xl-3 d-grid align-items-end"><button class="btn btn-primary mt-xl-4" type="submit">Delegate</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>From</th><th>To</th><th>Company</th><th>Period</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($delegations as $d):
                $live = (int) $d['active'] === 1 && $d['starts_at'] <= $now && $d['ends_at'] >= $now;
                $future = (int) $d['active'] === 1 && $d['starts_at'] > $now;
                $mineToEnd = $admin || (int) $d['delegator_id'] === (int) $me; ?>
                <tr>
                    <td data-label="From"><?= esc($d['delegator_name']) ?></td>
                    <td data-label="To"><?= esc($d['delegate_name']) ?></td>
                    <td data-label="Company"><?= esc($d['company_code'] ?? 'All') ?></td>
                    <td data-label="Period" class="small"><?= esc(date('j M Y H:i', (int) strtotime($d['starts_at']))) ?> → <?= esc(date('j M Y H:i', (int) strtotime($d['ends_at']))) ?><?= $d['reason'] ? '<div class="text-body-secondary">' . esc($d['reason']) . '</div>' : '' ?></td>
                    <td data-label="Status"><?= ! (int) $d['active'] ? '<span class="badge text-bg-secondary">Ended</span>' : ($live ? '<span class="badge text-bg-success">Active now</span>' : ($future ? '<span class="badge text-bg-info">Scheduled</span>' : '<span class="badge text-bg-secondary">Expired</span>')) ?></td>
                    <td class="actions text-end">
                        <?php if ((int) $d['active'] === 1 && $mineToEnd): ?>
                            <form method="post" action="<?= site_url($endBase . '/' . $d['id'] . '/end') ?>" onsubmit="return confirm('End this delegation?')"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">End</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($delegations === []): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No delegations yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
