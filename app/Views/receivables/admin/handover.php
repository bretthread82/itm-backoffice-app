<?php
/**
 * @var list<array<string, mixed>> $users
 * @var int $from
 * @var int $to
 * @var array<string, mixed>|null $plan
 * @var list<string> $missing
 * @var bool $canDisable
 */
$rows = $plan === null ? [] : [
    ['Approval and issue steps on invoices in progress', count($plan['steps']), 'They are re-assigned for the steps still ahead; steps already done keep their history, and a note is added to each invoice.'],
    ['Steps on approval routes', $plan['route_steps'], 'So that invoices submitted from now on go to the replacement.'],
    ['Unfinished invoices they raised', $plan['submitted'], 'Drafts, in approval, returned and rejected. Issued invoices stay as they are.'],
    ['Invoices they accepted for a team', $plan['claims'], 'These go back to the team to be accepted again.'],
    ['Active delegations', $plan['delegations'], 'Given or received; they are ended.'],
    ['Teams they belong to', $plan['teams'], 'Only changed if you also switch the account off.'],
];
$ready = $plan !== null && $to > 0 && $to !== $from && $missing === [];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-xl-9">
    <p class="text-body-secondary">
        Use this when someone leaves, is dismissed or is away for a long time. Their customer invoice work moves to a replacement so nothing
        waits on a person who is no longer there. History is never rewritten. Rights are not copied: the replacement must already have the
        company rights the work needs (shown below once you choose them).
    </p>

    <form method="get" action="<?= site_url('receivables-admin/handover') ?>" class="card card-body mb-3">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="from">Person leaving</label>
                <select class="form-select" id="from" name="from" required>
                    <option value="">— choose —</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $from === (int) $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?><?= $u['active'] ? '' : ' [inactive]' ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="to">Replacement</label>
                <select class="form-select" id="to" name="to">
                    <option value="">— choose —</option>
                    <?php foreach ($users as $u): if (! $u['active']) { continue; } ?><option value="<?= (int) $u['id'] ?>" <?= $to === (int) $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mt-3"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1"></i>Show what would move</button></div>
    </form>

    <?php if ($plan !== null): ?>
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table itm-table table-cards mb-0">
                    <thead><tr><th>Work</th><th class="text-end">Count</th><th>What happens</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as [$label, $count, $note]): ?>
                        <tr>
                            <td data-label="Work"><?= esc($label) ?></td>
                            <td data-label="Count" class="text-end fw-semibold"><?= (int) $count ?></td>
                            <td data-label="What happens" class="small text-body-secondary"><?= esc($note) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($missing !== []): ?>
            <div class="alert alert-warning small">
                <div class="fw-semibold">The replacement cannot take this over yet.</div>
                They are missing <?= esc(implode(', ', $missing)) ?>. Give them these under Manage company access, then try again.
            </div>
        <?php elseif ($ready): ?>
            <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i>The replacement has what this work needs.</div>
        <?php elseif ($to === 0): ?>
            <div class="alert alert-secondary small">Choose a replacement above, then show what would move again.</div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('receivables-admin/handover') ?>" class="card card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="from" value="<?= (int) $from ?>">
            <input type="hidden" name="to" value="<?= (int) $to ?>">
            <?php if ($canDisable): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="disable" name="disable" value="1">
                    <label class="form-check-label" for="disable">Also switch off their account and take them off every team</label>
                    <div class="form-text">Leave this unticked for a long absence. Switching the account off applies to the whole platform, not just customer invoices.</div>
                </div>
            <?php endif; ?>
            <div>
                <button class="btn btn-danger" type="submit" <?= $ready ? '' : 'disabled' ?>
                        data-confirm-click="Hand over this work now? Steps, routes and unfinished invoices move to the replacement and cannot be moved back automatically."><i class="bi bi-person-dash me-1"></i>Hand over the work</button>
            </div>
        </form>
    <?php endif; ?>
</div></div>
<?= $this->endSection() ?>
