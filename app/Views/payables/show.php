<?php
/**
 * @var array<string, mixed> $inv
 * @var array<string, mixed>|null $file
 * @var list<array<string, mixed>> $steps
 * @var array<int, array<string, mixed>> $acted
 * @var list<array<string, mixed>> $timeline
 * @var array<string, mixed> $can
 * @var list<array<string, mixed>> $documents supporting documents
 * @var array{allowed: bool, as: string|null, reason_required: bool} $canAttach
 */
$current   = $inv['current_step_order'] !== null ? (int) $inv['current_step_order'] : null;
$finished  = $inv['status'] === 'paid' || ($inv['status'] === 'processed' && $inv['current_step_order'] === null);
$active    = in_array($inv['status'], ['in_approval', 'approved'], true) || ($inv['status'] === 'processed' && $inv['current_step_order'] !== null);
$stepLabel = [];
$stepType  = [];
foreach ($steps as $s) {
    $stepType[(int) $s['order']]  = (string) $s['step_type'];
    $stepLabel[(int) $s['order']] = trim(($s['role'] ?? '') . (($s['label'] ?? '') !== '' ? ' (' . $s['label'] . ')' : ''));
}
$verbs = ['submit' => 'submitted the invoice for approval', 'resubmit' => 'resubmitted the invoice', 'approve' => 'approved', 'return' => 'returned the invoice for changes', 'return_previous' => 'returned the invoice to the previous approver', 'reject' => 'rejected the invoice', 'cancel' => 'cancelled the invoice', 'claim' => 'accepted the invoice for their team', 'release' => 'released the invoice back to the team', 'attach' => 'attached a supporting document'];
$verbFor = static fn (array $e): string => $e['action'] === 'approve' && ($stepType[(int) $e['step_order']] ?? '') === 'process' ? 'processed the invoice'
    : ($e['action'] === 'approve' && ($stepType[(int) $e['step_order']] ?? '') === 'pay' ? 'marked the invoice as paid' : ($verbs[$e['action']] ?? $e['action']));
$kindLabel = ['note' => 'Note', 'return_reason' => 'Reason for return', 'reject_reason' => 'Reason for rejection', 'resubmit_reason' => 'What changed', 'override_reason' => 'Group-level reason'];
$pastDue = $active && $inv['due_date'] ? days_overdue($inv['due_date'], (int) $inv['company_id']) : 0;

// Step at which the invoice was last returned or rejected, so the timeline can show it in red.
$badStep = null;
foreach ($timeline as $e) {
    if (($e['type'] ?? '') === 'action' && in_array($e['action'], ['return', 'reject'], true) && $e['step_order'] !== null) {
        $badStep = (int) $e['step_order'];
    }
}
$sharepointOk = false; // SharePoint filing is a phase 2 feature; the links below stay hidden until then.
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="page-header">
    <div>
        <a class="small" href="<?= site_url('payables') ?>"><i class="bi bi-arrow-left"></i> Invoice Register</a>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
            <h1><?= esc($inv['invoice_number'] ?: 'Draft #' . $inv['id']) ?></h1>
            <?= invoice_status_badge($inv['status']) ?> <?= invoice_priority_badge($inv) ?> <?= invoice_age_badge($pastDue) ?>
        </div>
        <div class="subtitle"><?= esc($inv['company_code']) ?> · <?= esc($inv['type_name']) ?> · uploaded by <?= esc($inv['submitter_name'] ?: 'unknown') ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?><a class="btn btn-outline-secondary" href="<?= site_url('payables/' . $inv['id'] . '/edit') ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?>
        <?php if ($file && $fileExists): ?>
            <a class="btn btn-outline-secondary" href="<?= site_url('payables/' . $inv['id'] . '/file?download=1') ?>"><i class="bi bi-download me-1"></i>Download</a>
        <?php endif; ?>
        <?php if (($file && $fileExists) || $canAttach['allowed']): ?>
            <div class="dropdown">
                <button class="btn btn-outline-secondary no-caret" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="bi bi-three-dots"></i> More</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php if ($file && $fileExists): ?><li><a class="dropdown-item" href="<?= site_url('payables/' . $inv['id'] . '/file') ?>" target="_blank" rel="noopener">Open in new tab</a></li><?php endif; ?>
                    <?php if ($sharepointOk): ?><li><a class="dropdown-item" href="<?= htmlspecialchars($inv['sharepoint_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open in SharePoint</a></li><?php endif; ?>
                    <?php if ($canAttach['allowed']): ?><li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#quick-upload"><i class="bi bi-paperclip me-1"></i>Upload supporting document</button></li><?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <?php if ($can['can_claim']): ?>
            <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/claim') ?>" class="card border-primary mb-3 action-bar">
                <?= csrf_field() ?>
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="flex-grow-1">
                        <div class="fw-semibold">Waiting for your team<?= $currentLabel !== '' ? ': ' . esc($currentLabel) : '' ?></div>
                        <div class="small text-body-secondary">Nobody has accepted this yet. Accept it to take it on; the rest of the team will see that it is yours.</div>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-hand-thumbs-up me-1"></i>Accept invoice</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (! empty($can['claim']) && ! $can['can_act']): ?>
            <div class="alert alert-secondary py-2">Accepted by <strong><?= esc($can['claim']['claimed_by_name']) ?></strong> (<?= esc($currentLabel) ?>).</div>
        <?php endif; ?>

        <?php if ($can['can_act']): ?>
            <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/act') ?>" class="card border-primary mb-3 action-bar" id="act-form">
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="fw-semibold mb-1">Waiting for you<?= $currentLabel !== '' ? ': ' . esc($currentLabel) : '' ?></div>
                    <?php if ($can['as'] === 'team'): ?>
                        <div class="small text-body-secondary mb-2"><span class="badge text-bg-primary">Accepted by you</span> Only you can act on this until you release it back to the team.</div>
                    <?php elseif ($can['as'] === 'delegate'): ?>
                        <div class="small text-body-secondary mb-2"><span class="badge text-bg-info">Delegate</span> You are acting on someone else's behalf. This is recorded.</div>
                    <?php elseif ($can['as'] === 'group_level'): ?>
                        <div class="alert alert-warning py-2 small mb-2">
                            <span class="badge text-bg-danger">Group-level action</span>
                            This step is not yours. Anything you do is recorded under your name, flagged as a group-level action, and the person named on the step is told. <strong>A reason is required.</strong>
                        </div>
                    <?php endif; ?>
                    <?php if (($can['step']['step_type'] ?? '') === 'pay'): ?>
                        <div class="row g-2 mb-2">
                            <div class="col-sm-7">
                                <label class="form-label small mb-1" for="payment_reference">Payment reference <span class="text-danger">(required to mark as paid)</span></label>
                                <input class="form-control" id="payment_reference" name="payment_reference" maxlength="100" value="<?= esc((string) old('payment_reference')) ?>">
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label small mb-1" for="payment_date">Payment date <span class="text-danger">(required)</span></label>
                                <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= esc((string) old('payment_date')) ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                    <label class="form-label small mb-1" for="note">Note <?= $can['as'] === 'group_level' ? '<span class="text-danger">(required)</span>' : '<span class="text-body-secondary">(required to return or reject)</span>' ?></label>
                    <textarea class="form-control mb-2" id="note" name="note" rows="2" maxlength="5000" data-need-note="<?= $can['as'] === 'group_level' ? '1' : '0' ?>"></textarea>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-success flex-fill" type="submit" name="decision" value="approve"><i class="bi bi-check-lg me-1"></i><?= ['process' => 'Mark as processed', 'pay' => 'Mark as paid'][$can['step']['step_type'] ?? ''] ?? 'Approve' ?></button>
                        <?php if ($can['previous_step'] !== null): ?>
                            <div class="btn-group flex-fill">
                                <button class="btn btn-warning dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-arrow-return-left me-1"></i>Return for changes</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" type="submit" name="decision" value="return" data-note-required>Return to submitter</button></li>
                                    <li><button class="dropdown-item" type="submit" name="decision" value="return_previous" data-note-required>Return to previous approver<span class="d-block small text-body-secondary"><?= esc($stepLabel[(int) $can['previous_step']['order']] ?? '') ?></span></button></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <button class="btn btn-warning flex-fill" type="submit" name="decision" value="return" data-note-required><i class="bi bi-arrow-return-left me-1"></i>Return for changes</button>
                        <?php endif; ?>
                        <button class="btn btn-outline-danger flex-fill" type="submit" name="decision" value="reject" data-note-required><i class="bi bi-x-lg me-1"></i>Reject</button>
                    </div>
                    <?php if ($can['can_release']): ?>
                        <div class="mt-2"><button class="btn btn-sm btn-link p-0" type="submit" formaction="<?= site_url('payables/' . $inv['id'] . '/release') ?>"><i class="bi bi-box-arrow-left me-1"></i>Release back to the team</button><?= $can['as'] === 'group_level' ? ' <span class="small text-body-secondary">(uses the note above as your reason)</span>' : '' ?></div>
                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($active && $currentLabel !== ''): ?>
            <div class="alert alert-secondary py-2">Waiting for <strong><?= esc($currentLabel) ?></strong>.</div>
        <?php endif; ?>

        <?php if ($isSubmitter && in_array($inv['status'], ['draft', 'returned', 'rejected'], true)): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <?php if ($problems !== []): ?>
                        <div class="alert alert-warning py-2 small"><div class="fw-semibold">Before you can submit:</div><ul class="mb-0"><?php foreach ($problems as $p): ?><li><?= esc($p) ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>
                    <?php if ($inv['status'] === 'draft'): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-secondary" href="<?= site_url('payables/' . $inv['id'] . '/edit') ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                            <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/submit') ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit" <?= $problems !== [] ? 'disabled' : '' ?>><i class="bi bi-send me-1"></i>Submit for approval</button></form>
                            <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/cancel') ?>" data-confirm="Cancel this invoice?"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit">Cancel invoice</button></form>
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/resubmit') ?>">
                            <?= csrf_field() ?>
                            <label class="form-label small mb-1" for="resub-note">What did you change? <span class="text-danger">(required)</span></label>
                            <textarea class="form-control mb-2" id="resub-note" name="note" rows="2" maxlength="5000" required></textarea>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit" <?= $problems !== [] ? 'disabled' : '' ?>><i class="bi bi-send me-1"></i>Resubmit for approval</button>
                                <a class="btn btn-outline-secondary" href="<?= site_url('payables/' . $inv['id'] . '/edit') ?>"><i class="bi bi-pencil me-1"></i>Edit details or PDF</a>
                                <button class="btn btn-outline-danger" type="submit" formaction="<?= site_url('payables/' . $inv['id'] . '/cancel') ?>" formnovalidate data-confirm-click="Cancel this invoice?">Cancel invoice</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Summary cards -->
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="stat-label mb-1">Supplier</div>
                    <div class="fw-semibold fs-6"><?= esc($inv['supplier'] ?: '—') ?></div>
                    <div class="small text-body-secondary mt-2">Uploaded by <?= esc($inv['submitter_name'] ?: 'unknown') ?></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body small">
                    <div class="stat-label mb-2">Invoice information</div>
                    <dl class="row mb-0 gy-1">
                        <dt class="col-5 fw-normal text-body-secondary">Invoice date</dt><dd class="col-7 mb-0"><?= esc($inv['invoice_date'] ?: '—') ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary">Due date</dt><dd class="col-7 mb-0"><?= esc($inv['due_date'] ?: '—') ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary">PO / reference</dt><dd class="col-7 mb-0"><?= esc($inv['po_reference'] ?: '—') ?></dd>
                        <?php if (! empty($inv['payment_reference'])): ?>
                            <dt class="col-5 fw-normal text-body-secondary">Payment ref.</dt><dd class="col-7 mb-0"><?= esc($inv['payment_reference']) ?></dd>
                            <dt class="col-5 fw-normal text-body-secondary">Paid on</dt><dd class="col-7 mb-0"><?= esc($inv['payment_date'] ?: '—') ?></dd>
                        <?php endif; ?>
                        <dt class="col-5 fw-normal text-body-secondary">Company</dt><dd class="col-7 mb-0"><?= esc($inv['company_code']) ?></dd>
                    </dl>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="stat-label mb-1">Total amount</div>
                    <div class="money-big"><?= invoice_money($inv['amount'], $inv['currency']) ?></div>
                    <div class="small text-body-secondary mt-2">Submitted <?= esc($inv['submitted_at'] ? local_time($inv['submitted_at'], 'Y-m-d H:i') : '—') ?></div>
                </div></div>
            </div>
        </div>

        <ul class="nav nav-tabs-mg mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab">Overview</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-documents" type="button" role="tab">Documents (<?= ($file ? 1 : 0) + count($documents) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-approval" type="button" role="tab">Approval &amp; notes</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab">Activity (<?= count($timeline) ?>)</button></li>
        </ul>

        <div class="tab-content">
            <!-- Overview: invoice PDF and filing status -->
            <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">

                <?php if ($file && $fileExists): ?>
                    <div class="pdf-viewer" data-url="<?= site_url('payables/' . $inv['id'] . '/file') ?>" data-worker="<?= base_url('assets/vendor/pdfjs/pdf.worker.min.js') ?>">
                        <div class="d-flex flex-wrap gap-1 p-2 bg-body-tertiary border-bottom align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-action="prev" aria-label="Previous page"><i class="bi bi-chevron-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-action="next" aria-label="Next page"><i class="bi bi-chevron-down"></i></button>
                            <span class="small px-1" data-pdf-label aria-live="polite"></span>
                            <span class="ms-auto d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-action="zoom-out" aria-label="Zoom out"><i class="bi bi-zoom-out"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-action="fit" aria-label="Fit to width"><i class="bi bi-arrows-angle-expand"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-action="zoom-in" aria-label="Zoom in"><i class="bi bi-zoom-in"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-pdf-action="fullscreen" aria-label="Full screen"><i class="bi bi-fullscreen"></i></button>
                            </span>
                        </div>
                        <div class="small p-2 text-body-secondary" data-pdf-status>Loading the invoice…</div>
                        <div data-pdf-pages></div>
                    </div>
                    <div class="d-flex gap-3 mt-2 small">
                        <a href="<?= site_url('payables/' . $inv['id'] . '/file') ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open in new tab</a>
                        <a href="<?= site_url('payables/' . $inv['id'] . '/file?download=1') ?>"><i class="bi bi-download me-1"></i>Download</a>
                        <span class="text-body-secondary ms-auto"><?= esc($file['original_name']) ?></span>
                    </div>
                <?php elseif ($file && $file['purged_at']): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-cloud-check me-1"></i>The file is no longer kept on this server.
                        <?php if ($sharepointOk): ?><a href="<?= htmlspecialchars($inv['sharepoint_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open it in SharePoint</a><?php else: ?>It was stored in SharePoint.<?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0"><i class="bi bi-file-earmark-x me-1"></i>No PDF is attached to this invoice yet.</div>
                <?php endif; ?>
            </div>

            <!-- Documents -->
            <div class="tab-pane fade" id="tab-documents" role="tabpanel">
                <?php if ($file): ?>
                    <div class="file-card">
                        <i class="bi bi-file-earmark-pdf fs-3 text-danger"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= esc($file['original_name']) ?></div>
                            <div class="small text-body-secondary">Invoice PDF · <?= esc(number_format(((int) $file['size_bytes']) / 1024, 0)) ?> KB · uploaded <?= esc(time_ago($file['uploaded_at'])) ?></div>
                        </div>
                        <?php if ($fileExists): ?><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('payables/' . $inv['id'] . '/file?download=1') ?>"><i class="bi bi-download"></i></a>
                        <?php elseif ($sharepointOk): ?><a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($inv['sharepoint_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">SharePoint</a><?php endif; ?>
                    </div>
                <?php elseif ($documents === []): ?>
                    <div class="text-body-secondary">No documents are attached yet.</div>
                <?php endif; ?>

                <?php if ($documents !== []): ?>
                    <h2 class="h6 mt-3 mb-2">Supporting documents</h2>
                    <?php foreach ($documents as $d):
                        $gone = ! empty($d['purged_at']);
                        $icon = match (true) {
                            str_starts_with((string) $d['mime'], 'image/') => 'bi-file-earmark-image',
                            $d['mime'] === 'application/pdf' => 'bi-file-earmark-pdf text-danger',
                            str_contains((string) $d['mime'], 'spreadsheet') => 'bi-file-earmark-excel text-success',
                            str_contains((string) $d['mime'], 'wordprocessing') => 'bi-file-earmark-word text-primary',
                            default => 'bi-file-earmark-text',
                        }; ?>
                        <div class="file-card mb-2">
                            <i class="bi <?= $icon ?> fs-3"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold text-break"><?= esc($d['original_name']) ?></div>
                                <div class="small text-body-secondary">
                                    <?= esc(number_format(((int) $d['size_bytes']) / 1024, 0)) ?> KB · added by <?= esc($d['uploader_name'] ?: $d['uploader_username'] ?: 'unknown') ?> · <?= esc(time_ago($d['uploaded_at'])) ?>
                                    <?php if ($d['acted_as'] === 'group_level'): ?><span class="badge text-bg-danger">Group-level</span><?php endif; ?>
                                </div>
                            </div>
                            <?php if (! $gone): ?>
                                <?php if (\App\Core\Payables\InvoiceDocuments::isInline((string) $d['mime'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= site_url('payables/' . $inv['id'] . '/documents/' . $d['id']) ?>" aria-label="Open <?= esc($d['original_name'], 'attr') ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('payables/' . $inv['id'] . '/documents/' . $d['id'] . '?download=1') ?>" aria-label="Download <?= esc($d['original_name'], 'attr') ?>"><i class="bi bi-download"></i></a>
                            <?php elseif (($d['sync_status'] ?? '') === 'synced' && str_starts_with((string) ($d['sharepoint_url'] ?? ''), 'https://')): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars((string) $d['sharepoint_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">SharePoint</a>
                            <?php else: ?>
                                <span class="small text-body-secondary">No longer on this server</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($canAttach['allowed']): ?>
                    <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/documents') ?>" enctype="multipart/form-data" class="card card-body mt-3">
                        <?= csrf_field() ?>
                        <h2 class="h6">Attach a supporting document</h2>
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="doc-file">File</label>
                            <input class="form-control" type="file" id="doc-file" name="document" accept="<?= esc(\App\Core\Payables\InvoiceDocuments::acceptAttribute(), 'attr') ?>" required>
                            <div class="form-text">Allowed: <?= esc(\App\Core\Payables\InvoiceDocuments::acceptedExtensions()) ?>. Macros and embedded objects are not accepted.</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="doc-note">Why is it needed?<?= $canAttach['reason_required'] ? '' : ' (optional)' ?></label>
                            <textarea class="form-control" id="doc-note" name="note" rows="2" maxlength="5000" <?= $canAttach['reason_required'] ? 'required' : '' ?>></textarea>
                            <?php if ($canAttach['reason_required']): ?><div class="form-text">You are acting as a group-level user, so a reason is required.</div><?php endif; ?>
                        </div>
                        <div><button class="btn btn-outline-primary" type="submit"><i class="bi bi-paperclip me-1"></i>Attach</button></div>
                        <div class="form-text mt-2">Attached documents stay with the invoice and cannot be edited or deleted.</div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Approval history and notes -->
            <div class="tab-pane fade" id="tab-approval" role="tabpanel">
                <div class="card">
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($timeline as $e): ?>
                            <li class="list-group-item">
                                <?php if ($e['type'] === 'note'): ?>
                                    <div><strong><?= esc($e['actor']) ?></strong> added a note <span class="text-body-secondary">· <?= esc(time_ago($e['at'])) ?></span></div>
                                <?php else:
                                    $verb = $verbFor($e);
                                    $step = $e['step_order'] !== null && in_array($e['action'], ['approve', 'return', 'return_previous', 'reject', 'claim', 'release'], true) ? ' (' . ($stepLabel[(int) $e['step_order']] ?? 'step ' . $e['step_order']) . ')' : ''; ?>
                                    <div>
                                        <strong><?= esc($e['actor']) ?></strong> <?= esc($verb) ?><?= esc($step) ?>
                                        <?php if ($e['acted_as'] === 'group_level'): ?><span class="badge text-bg-danger">Group-level override</span><?php endif; ?>
                                        <?php if ($e['acted_as'] === 'delegate'): ?><span class="badge text-bg-info">Delegate</span><?php endif; ?>
                                        <?php if ($e['on_behalf_name'] && $e['acted_as'] !== 'normal'): ?><span class="text-body-secondary">on behalf of <?= esc($e['on_behalf_name']) ?></span><?php endif; ?>
                                        <span class="text-body-secondary">· <?= esc(time_ago($e['at'])) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (! empty($e['note_body'])): ?>
                                    <div class="border-start border-3 ps-2 mt-1 bg-body-tertiary rounded-end py-1">
                                        <?php if (($e['note_kind'] ?? 'note') !== 'note'): ?><div class="text-body-secondary text-uppercase inv-fs-7" ><?= esc($kindLabel[$e['note_kind']] ?? '') ?></div><?php endif; ?>
                                        <?= nl2br(esc($e['note_body'])) ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($timeline === []): ?><li class="list-group-item text-body-secondary">Nothing has happened yet.</li><?php endif; ?>
                    </ul>
                    <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/note') ?>" class="card-body border-top">
                        <?= csrf_field() ?>
                        <label class="form-label small mb-1" for="new-note">Add a note</label>
                        <div class="input-group">
                            <textarea class="form-control" id="new-note" name="note" rows="1" maxlength="5000" required></textarea>
                            <button class="btn btn-outline-primary" type="submit">Add</button>
                        </div>
                        <div class="form-text">Notes stay with the invoice and cannot be edited or deleted.</div>
                    </form>
                </div>
            </div>

            <!-- Activity: every recorded event, newest first -->
            <div class="tab-pane fade" id="tab-activity" role="tabpanel">
                <div class="card"><div class="card-body py-2">
                    <?php foreach (array_reverse($timeline) as $e):
                        $isNote = $e['type'] === 'note';
                        $what   = $isNote ? 'added a note' : $verbFor($e); ?>
                        <div class="feed-item">
                            <span class="itm-avatar sm"><?= esc(strtoupper(mb_substr((string) $e['actor'], 0, 1))) ?></span>
                            <div>
                                <div><strong><?= esc($e['actor']) ?></strong> <?= esc($what) ?>
                                    <?php if (! $isNote && $e['acted_as'] === 'group_level'): ?><span class="badge text-bg-danger">Group-level override</span><?php endif; ?></div>
                                <div class="small text-body-secondary"><?= esc($e['at']) ?> · <?= esc(time_ago($e['at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($timeline === []): ?><div class="text-body-secondary py-2">Nothing has happened yet.</div><?php endif; ?>
                </div></div>
            </div>
        </div>
    </div>

    <!-- Approval workflow -->
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Approval workflow</div>
            <div class="card-body">
                <?php if ($steps === []): ?>
                    <div class="text-body-secondary small">No approval route has been set for this invoice yet.</div>
                <?php else: ?>
                    <?= $this->setData([
                        'tlSteps' => $steps, 'tlActed' => $acted, 'tlCurrent' => $current,
                        'tlFinished' => $finished, 'tlStatus' => $inv['status'], 'tlBadStep' => $badStep,
                    ])->include('payables/components/approval_timeline') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if ($canAttach['allowed']): ?>
    <div class="modal fade" id="quick-upload" tabindex="-1" aria-labelledby="quick-upload-title" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" action="<?= site_url('payables/' . $inv['id'] . '/documents') ?>" enctype="multipart/form-data" class="modal-content">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="quick-upload-title">Upload supporting document</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small mb-1" for="quick-file">File</label>
                    <input class="form-control mb-1" type="file" id="quick-file" name="document" accept="<?= esc(\App\Core\Payables\InvoiceDocuments::acceptAttribute(), 'attr') ?>" required>
                    <div class="form-text mb-2">Allowed: <?= esc(\App\Core\Payables\InvoiceDocuments::acceptedExtensions()) ?>.</div>
                    <label class="form-label small mb-1" for="quick-note">Why is it needed?<?= $canAttach['reason_required'] ? ' <span class="text-danger">(required)</span>' : ' (optional)' ?></label>
                    <textarea class="form-control" id="quick-note" name="note" rows="2" maxlength="5000" <?= $canAttach['reason_required'] ? 'required' : '' ?>></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($file && $fileExists): ?>
    <script src="<?= base_url('assets/vendor/pdfjs/pdf.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/pdf-viewer.js') ?>"></script>
<?php endif; ?>
<script {csp-script-nonce}>
(function () {
    var form = document.getElementById('act-form');
    if (!form) { return; }
    var note = document.getElementById('note');
    form.addEventListener('submit', function (e) {
        var btn = e.submitter;
        var needs = note.dataset.needNote === '1' || (btn && btn.hasAttribute('data-note-required'));
        if (needs && note.value.trim() === '') {
            e.preventDefault();
            note.setCustomValidity('Please write a note first.');
            note.reportValidity();
        }
    });
    note.addEventListener('input', function () { note.setCustomValidity(''); });
})();
</script>
<?= $this->endSection() ?>
