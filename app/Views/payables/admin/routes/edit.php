<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php $isVariant = $route['variant'] !== 'default'; ?>
<div class="mb-3">
    <a class="small" href="<?= site_url('payables-admin/routes') ?>"><i class="bi bi-arrow-left"></i> All routes</a>
    <h1 class="h3 mb-0 mt-1"><?= esc($route['type_name']) ?>
        <?= $isVariant ? '<span class="badge text-bg-info fs-6"><?= esc($variantName) ?></span>' : '' ?></h1>
    <div class="text-body-secondary"><?= esc($route['code']) ?> · <?= esc($route['company_name']) ?> · version <?= (int) $route['version'] ?></div>
</div>

<form method="post" action="<?= site_url('payables-admin/invoice-types/' . $route['invoice_type_id'] . '/priority') ?>" class="card card-body mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="payables-admin/routes/<?= (int) $route['id'] ?>/edit">
    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="high_priority" name="high_priority" value="1" <?= (int) $route['high_priority'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="high_priority"><strong>High priority invoice type</strong></label>
            </div>
            <div class="form-text">
                Approvers get an email and a bell alert for these invoices that they cannot switch off. Applies to all routes of this type, for invoices submitted from now on.
                Managers can also set it for every type under Invoice Types.
            </div>
        </div>
        <button class="btn btn-outline-primary" type="submit">Save priority</button>
    </div>
</form>

<form method="post" action="<?= site_url('payables-admin/routes/' . $route['id']) ?>" id="route-form">
    <?= csrf_field() ?>

    <div class="card card-body mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= old('active', $route['active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="active"><strong>Route is on</strong></label>
                </div>
                <div class="form-text">Only routes that are on can be chosen when submitting an invoice.</div>
            </div>
            <?php if ($isVariant): ?>
                <div class="col-md-4">
                    <label class="form-label" for="variant_trigger">When does this "<?= esc($variantName) ?>" route apply?</label>
                    <select class="form-select" id="variant_trigger" name="variant_trigger">
                        <?php foreach (['none' => 'Not decided yet', 'manual_flag' => 'Submitter ticks "extra approval"', 'amount_threshold' => 'Invoice amount at or above…'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= old('variant_trigger', $route['variant_trigger']) === $v ? 'selected' : '' ?>><?= esc($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="threshold-wrap" <?= old('variant_trigger', $route['variant_trigger']) === 'amount_threshold' ? '' : 'hidden' ?>>
                    <label class="form-label" for="trigger_value">Amount</label>
                    <input class="form-control" id="trigger_value" name="trigger_value" inputmode="decimal" value="<?= esc(old('trigger_value', $route['trigger_value'] ?? '')) ?>">
                </div>
            <?php else: ?>
                <input type="hidden" name="variant_trigger" value="none">
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Steps</strong>
            <span class="text-body-secondary small">Drag the handle, or use the arrows, to reorder.</span>
        </div>
        <div class="list-group list-group-flush" id="steps">
            <?php foreach ($steps as $i => $s): ?>
                <?= route_step_row('s' . $i, $s, $users, $teams) ?>
            <?php endforeach; ?>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-outline-primary btn-sm" id="add-step"><i class="bi bi-plus-lg me-1"></i>Add step</button>
            <span class="small text-body-secondary" id="empty-hint" hidden>No steps yet.</span>
        </div>
    </div>

    <details class="mb-3 small text-body-secondary">
        <summary>What the step types mean</summary>
        <ul class="mt-2">
            <li><strong>Submit</strong>: the person or mailbox that raises the invoice. Invoices are uploaded in the app, so this step is completed by the submitter.</li>
            <li><strong>Approve</strong>: must approve before the invoice moves on.</li>
            <li><strong>Process</strong>: the invoice is handled (for example entered in the accounts system) after approval. Completing it marks the invoice <em>processed</em> and files it to SharePoint.</li>
            <li><strong>Pay</strong>: the payment is made. The person must enter a payment reference and payment date to complete it, which marks the invoice <em>paid</em>. A route has at most one, after every approve and process step. Leave it out if you do not track payment here.</li>
            <li><strong>Notify</strong>: informed only, no action needed.</li>
            <li><strong>A team</strong> (approve, process or pay steps only): everyone on the team is told; one member accepts the invoice, and from then on only that person can act on it.</li>
        </ul>
    </details>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save route</button>
        <a class="btn btn-outline-secondary" href="<?= site_url('payables-admin/routes') ?>">Back</a>
    </div>
    <div class="form-text mt-2">Saving does not change invoices already in approval: each invoice keeps the route it was submitted with.</div>
</form>

<datalist id="job-titles">
    <?php foreach ($titles as $t): ?><option value="<?= esc($t) ?>"></option><?php endforeach; ?>
</datalist>

<template id="step-template"><?= route_step_row('__IDX__', ['step_type' => 'approve', 'assignee_kind' => 'user'], $users, $teams) ?></template>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script {csp-script-nonce}>
(function () {
    var list = document.getElementById('steps');
    var tpl = document.getElementById('step-template');
    var hint = document.getElementById('empty-hint');
    var counter = 0;

    function rows() { return Array.prototype.slice.call(list.querySelectorAll('[data-step]')); }

    function refresh() {
        var all = rows();
        all.forEach(function (row, i) {
            row.querySelector('.step-no').textContent = i + 1;
            row.querySelector('[data-move="up"]').disabled = i === 0;
            row.querySelector('[data-move="down"]').disabled = i === all.length - 1;
        });
        hint.hidden = all.length > 0;
    }

    function syncKind(row) {
        var kind = row.querySelector('.kind-select').value;
        row.querySelector('.kind-user').hidden = kind !== 'user';
        row.querySelector('.kind-team').hidden = kind !== 'team';
        row.querySelector('.kind-other').hidden = kind === 'user' || kind === 'team';
    }

    list.addEventListener('change', function (e) {
        if (e.target.classList.contains('kind-select')) { syncKind(e.target.closest('[data-step]')); }
        if (e.target.matches('.kind-user select')) {
            // Prefill Role from the user's job title, unless the admin typed their own.
            var row = e.target.closest('[data-step]');
            var role = row.querySelector('[name$="[job_title]"]');
            var title = e.target.selectedOptions[0] ? (e.target.selectedOptions[0].dataset.title || '') : '';
            if (title && (role.value === '' || role.value === role.dataset.auto)) {
                role.value = title;
                role.dataset.auto = title;
            }
        }
    });

    list.addEventListener('click', function (e) {
        var row = e.target.closest('[data-step]');
        if (!row) { return; }
        var move = e.target.closest('[data-move]');
        if (move) {
            if (move.dataset.move === 'up' && row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); }
            if (move.dataset.move === 'down' && row.nextElementSibling) { list.insertBefore(row.nextElementSibling, row); }
            refresh();
        } else if (e.target.closest('[data-remove]')) {
            row.remove();
            refresh();
        }
    });

    document.getElementById('add-step').addEventListener('click', function () {
        counter += 1;
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__IDX__/g, 'n' + counter);
        var row = holder.firstElementChild;
        list.appendChild(row);
        syncKind(row);
        refresh();
        var first = row.querySelector('input, select');
        if (first) { first.focus(); }
    });

    // Drag and drop (mouse). The row only becomes draggable while its handle is held.
    var dragging = null;
    list.addEventListener('mousedown', function (e) {
        var handle = e.target.closest('.step-handle');
        if (handle) { handle.closest('[data-step]').draggable = true; }
    });
    list.addEventListener('dragstart', function (e) {
        dragging = e.target.closest('[data-step]');
        if (dragging) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); dragging.classList.add('opacity-50'); }
    });
    list.addEventListener('dragover', function (e) {
        if (!dragging) { return; }
        e.preventDefault();
        var over = e.target.closest('[data-step]');
        if (!over || over === dragging) { return; }
        var box = over.getBoundingClientRect();
        list.insertBefore(dragging, e.clientY < box.top + box.height / 2 ? over : over.nextElementSibling);
    });
    list.addEventListener('dragend', function () {
        if (dragging) { dragging.classList.remove('opacity-50'); dragging.draggable = false; dragging = null; refresh(); }
    });

    var trigger = document.getElementById('variant_trigger');
    if (trigger) {
        trigger.addEventListener('change', function () {
            document.getElementById('threshold-wrap').hidden = trigger.value !== 'amount_threshold';
        });
    }

    rows().forEach(syncKind);
    refresh();
})();
</script>
<?= $this->endSection() ?>
