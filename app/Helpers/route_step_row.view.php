<?php
/**
 * One step in the route builder. Rendered once per saved step and once as the "add step" template
 * (idx = __IDX__).
 *
 * @var string $idx
 * @var array<string, mixed> $step
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $teams
 */
$kind = $step['assignee_kind'] ?? 'user';
?>
<div class="list-group-item step-row" data-step>
    <div class="row g-2 align-items-start">
        <div class="col-auto d-flex align-items-center gap-2 pt-1">
            <span class="step-handle text-body-secondary" style="cursor: grab;" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical fs-5"></i></span>
            <span class="badge text-bg-dark rounded-pill step-no" aria-label="Step number">1</span>
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Type</label>
            <select class="form-select form-select-sm" name="steps[<?= esc($idx) ?>][step_type]">
                <?php foreach (['submit' => 'Submit', 'approve' => 'Approve', 'process' => 'Process', 'pay' => 'Pay', 'notify' => 'Notify'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($step['step_type'] ?? 'approve') === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label small mb-0">Role</label>
            <input class="form-control form-control-sm" list="job-titles" name="steps[<?= esc($idx) ?>][job_title]" maxlength="150" value="<?= esc($step['job_title'] ?? '') ?>" placeholder="e.g. Accountant">
            <div class="form-text mt-0">Filled from the chosen user's job title.</div>
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Assigned to</label>
            <select class="form-select form-select-sm kind-select" name="steps[<?= esc($idx) ?>][assignee_kind]">
                <?php foreach (['user' => 'A user', 'external' => 'Outside party', 'team' => 'A team'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $kind === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-3">
            <div class="kind-user" <?= $kind === 'user' ? '' : 'hidden' ?>>
                <label class="form-label small mb-0">User</label>
                <select class="form-select form-select-sm" name="steps[<?= esc($idx) ?>][user_id]">
                    <option value="">— choose —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" data-title="<?= esc((string) ($u['job_title'] ?? '')) ?>" <?= (int) ($step['assignee_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= esc($u['name']) ?><?= $u['email_missing'] ? ' (no email yet)' : '' ?><?= $u['active'] ? '' : ' [inactive]' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="kind-team" <?= $kind === 'team' ? '' : 'hidden' ?>>
                <label class="form-label small mb-0">Team</label>
                <select class="form-select form-select-sm" name="steps[<?= esc($idx) ?>][team_id]">
                    <option value="">— choose —</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= (int) ($step['team_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?><?= $t['active'] ? '' : ' [inactive]' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="kind-other" <?= $kind === 'user' || $kind === 'team' ? 'hidden' : '' ?>>
                <input class="form-control form-control-sm mb-1" name="steps[<?= esc($idx) ?>][label]" maxlength="200" placeholder="Name" value="<?= esc($kind === 'user' ? '' : ($step['assignee_label'] ?? '')) ?>" aria-label="Name">
                <input class="form-control form-control-sm" type="email" name="steps[<?= esc($idx) ?>][email]" maxlength="254" placeholder="Email address" value="<?= esc($step['assignee_email'] ?? '') ?>" aria-label="Email address">
            </div>
        </div>

        <div class="col-12 col-md-auto ms-md-auto d-flex gap-1 justify-content-end">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-move="up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-move="down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-remove aria-label="Remove step"><i class="bi bi-trash"></i></button>
        </div>

        <div class="col-12">
            <label class="form-label small mb-0">Time limit (days)</label>
            <input class="form-control form-control-sm" type="number" min="1" max="365" step="1" name="steps[<?= esc($idx) ?>][sla_days]" value="<?= esc((string) ($step['sla_days'] ?? '')) ?>" placeholder="None" style="max-width: 8rem;">
            <div class="form-text mt-0">Approve, process and pay steps. After this long the approver is reminded; after twice as long, group-level users are told. Blank means no reminders.</div>
        </div>
    </div>
</div>
