<?php
/** @var array<string, mixed> $invoice */
$resub = in_array($status, ['returned', 'rejected'], true);
$val   = static fn (string $k, mixed $default = '') => old($k, $invoice[$k] ?? $default);
$maxMb = (int) setting('InvoiceSettings.maxUploadMb');

// The customer is picked from the shared list. A name that is not on the list (an older invoice, or a one-off buyer) is kept as typed.
$chosenCustomer = (string) $val('customer_id');
$typedName      = '';
if ($chosenCustomer === '' && trim((string) $val('customer')) !== '') {
    $typedName = (string) $val('customer');
}
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-xl-9">
    <div class="page-header">
        <div>
            <h1><?= esc($title) ?></h1>
            <div class="subtitle">Enter the invoice details and attach the invoice PDF from your accounting system. Fields marked <span class="text-danger">*</span> are required.</div>
        </div>
    </div>

    <?php if ($resub): ?>
        <div class="alert alert-warning">
            This invoice was <strong><?= $status === 'rejected' ? 'rejected' : 'returned for changes' ?></strong>.
            Fix what was asked, then resubmit with a note describing what you changed.
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="<?= site_url($id === null ? 'receivables' : 'receivables/' . $id) ?>" class="card card-body" id="ar-form" data-types-url="<?= site_url('receivables/types') ?>" novalidate>
        <?= csrf_field() ?>

        <h2 class="h6 text-uppercase text-body-secondary mb-3">Invoice details</h2>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="company_id">Company <span class="text-danger">*</span></label>
                <select class="form-select" id="company_id" name="company_id" required>
                    <option value="">— choose —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (string) $val('company_id') === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?> · <?= esc($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_type_id">Invoice type <span class="text-danger">*</span></label>
                <select class="form-select" id="invoice_type_id" name="invoice_type_id" required data-selected="<?= esc((string) $val('invoice_type_id')) ?>" disabled>
                    <option value="">Choose a company first</option>
                </select>
            </div>

            <div class="col-12" id="route-preview" hidden>
                <div class="border rounded p-2 small bg-body-tertiary">
                    <div class="fw-semibold mb-1"><i class="bi bi-signpost-split me-1"></i>Approval route for this type</div>
                    <div id="route-steps" class="d-flex flex-wrap gap-1"></div>
                </div>
            </div>
            <div class="col-12" id="extra-wrap" hidden>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="extra_approval" name="extra_approval" value="1" <?= (int) $val('extra_approval', 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="extra_approval">This invoice needs the extra approval step</label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="customer_id">Customer <span class="text-danger">*</span></label>
                <select class="form-select" id="customer_id" name="customer_id">
                    <option value="">— choose —</option>
                    <?php foreach ($customers as $cu): ?>
                        <option value="<?= $cu['id'] ?>" <?= $chosenCustomer === (string) $cu['id'] ? 'selected' : '' ?>><?= esc($cu['code'] . ' · ' . $cu['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($customers === []): ?><div class="form-text">No customers have been set up yet. Ask a manager to add them (Admin &gt; Customers), or type a name below.</div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="customer">Or a customer not on the list</label>
                <input class="form-control" id="customer" name="customer" maxlength="200" value="<?= esc($typedName) ?>">
                <div class="form-text">Only used when no customer is chosen on the left.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="invoice_number">Invoice number <span class="text-danger">*</span></label>
                <input class="form-control" id="invoice_number" name="invoice_number" required maxlength="100" value="<?= esc((string) $val('invoice_number')) ?>">
                <div class="form-text">Must be unique for the company.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="customer_po_reference">Customer PO / reference</label>
                <input class="form-control" id="customer_po_reference" name="customer_po_reference" maxlength="100" value="<?= esc((string) $val('customer_po_reference')) ?>">
            </div>
            <div class="col-6">
                <label class="form-label" for="invoice_date">Invoice date <span class="text-danger">*</span></label>
                <input class="form-control" type="date" id="invoice_date" name="invoice_date" required value="<?= esc((string) $val('invoice_date')) ?>">
            </div>
            <div class="col-6">
                <label class="form-label" for="due_date">Due date <span class="text-danger">*</span></label>
                <input class="form-control" type="date" id="due_date" name="due_date" required value="<?= esc((string) $val('due_date')) ?>">
            </div>
        </div>

        <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-3">Amount</h2>
        <div class="row g-3">
            <div class="col-7 col-md-4">
                <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                <input class="form-control" id="amount" name="amount" required inputmode="decimal" value="<?= esc((string) $val('amount')) ?>" placeholder="0.00">
            </div>
            <div class="col-5 col-md-4">
                <label class="form-label" for="currency">Currency <span class="text-danger">*</span></label>
                <?php
                $currencies = invoice_currencies();
                $chosen     = strtoupper((string) $val('currency', setting('InvoiceSettings.defaultCurrency')));
                if (! isset($currencies[$chosen])) {
                    $currencies = [$chosen => $chosen] + $currencies;   // keep an older invoice's code selectable
                }
                ?>
                <select class="form-select" id="currency" name="currency" required>
                    <?php foreach ($currencies as $code => $name): ?>
                        <option value="<?= esc($code) ?>" <?= $code === $chosen ? 'selected' : '' ?>><?= esc($code . ' - ' . $name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-3">Invoice PDF</h2>
        <?php if ($file): ?>
            <div class="file-card mb-3">
                <i class="bi bi-file-earmark-pdf fs-3 text-danger"></i>
                <div class="flex-grow-1"><div class="fw-semibold"><?= esc($file['original_name']) ?></div><div class="small text-body-secondary">Current file. Choose a new file only to replace it.</div></div>
            </div>
        <?php endif; ?>
        <label class="form-label" for="pdf">PDF, up to <?= $maxMb ?> MB</label>
        <input class="form-control" type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" data-max-mb="<?= $maxMb ?>">
        <div class="small text-danger mt-2" id="file-error" hidden></div>

        <?php if ($resub): ?>
            <div class="mt-4">
                <label class="form-label" for="note">What did you change? <span class="text-danger">*</span></label>
                <textarea class="form-control" id="note" name="note" rows="3" maxlength="5000" placeholder="Required to resubmit"><?= esc((string) old('note')) ?></textarea>
            </div>
        <?php endif; ?>
        <div class="form-text mt-3">Nothing is sent to approvers until you submit. Required to submit: a customer, the number, both dates, an amount above zero, and the invoice PDF.</div>

        <div class="d-flex flex-wrap gap-2 mt-4 align-items-center">
            <?php if ($resub): ?>
                <button class="btn btn-primary" type="submit" name="action" value="resubmit"><i class="bi bi-send me-1"></i>Resubmit for approval</button>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="save">Save changes</button>
            <?php else: ?>
                <button class="btn btn-primary" type="submit" name="action" value="submit"><i class="bi bi-send me-1"></i>Submit invoice</button>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="save">Save draft</button>
            <?php endif; ?>
            <a class="btn btn-link" href="<?= site_url($id === null ? 'receivables' : 'receivables/' . $id) ?>">Cancel</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receivables-form.js') ?>"></script>
<?= $this->endSection() ?>
