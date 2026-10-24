<?php
/** @var array<string, mixed> $sub */
$val = static fn (string $k, mixed $default = '') => old($k, $sub[$k] ?? $default);

$chosenCustomer = (string) $val('customer_id');
$typedName      = '';
if ($chosenCustomer === '' && trim((string) $val('customer')) !== '') {
    $typedName = (string) $val('customer');
}

$currencies = invoice_currencies();
$chosen     = strtoupper((string) $val('currency', setting('InvoiceSettings.defaultCurrency')));
if (! isset($currencies[$chosen])) {
    $currencies = [$chosen => $chosen] + $currencies;
}
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-xl-9">
    <div class="page-header">
        <div>
            <h1><?= esc($title) ?></h1>
            <div class="subtitle">Set up what the customer subscribes to and how often it is billed. Fields marked <span class="text-danger">*</span> are required.</div>
        </div>
    </div>

    <form method="post" action="<?= site_url($id === null ? 'receivables/subscriptions' : 'receivables/subscriptions/' . $id) ?>" class="card card-body" id="sub-form" data-types-url="<?= site_url('receivables/types') ?>" novalidate>
        <?= csrf_field() ?>

        <h2 class="h6 text-uppercase text-body-secondary mb-3">Who and what</h2>
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
                <div class="form-text">The draft invoice is raised with this type and follows its approval route.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="customer_id">Customer <span class="text-danger">*</span></label>
                <select class="form-select" id="customer_id" name="customer_id">
                    <option value="">— choose —</option>
                    <?php foreach ($customers as $cu): ?>
                        <option value="<?= $cu['id'] ?>" <?= $chosenCustomer === (string) $cu['id'] ? 'selected' : '' ?>><?= esc($cu['code'] . ' · ' . $cu['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($customers === []): ?><div class="form-text">No customers have been set up yet. Ask a manager to add them (Admin &gt; Customers), or type a name on the right.</div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="customer">Or a customer not on the list</label>
                <input class="form-control" id="customer" name="customer" maxlength="200" value="<?= esc($typedName) ?>">
                <div class="form-text">Only used when no customer is chosen on the left.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Service <span class="text-danger">*</span></label>
                <input class="form-control" id="description" name="description" required maxlength="200" value="<?= esc((string) $val('description')) ?>" placeholder="For example: Managed hosting, Gold support plan">
            </div>
        </div>

        <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-3">Billing</h2>
        <div class="row g-3">
            <div class="col-7 col-md-4">
                <label class="form-label" for="amount">Amount per cycle <span class="text-danger">*</span></label>
                <input class="form-control" id="amount" name="amount" required inputmode="decimal" value="<?= esc((string) $val('amount')) ?>" placeholder="0.00">
            </div>
            <div class="col-5 col-md-4">
                <label class="form-label" for="currency">Currency <span class="text-danger">*</span></label>
                <select class="form-select" id="currency" name="currency" required>
                    <?php foreach ($currencies as $code => $name): ?>
                        <option value="<?= esc($code) ?>" <?= $code === $chosen ? 'selected' : '' ?>><?= esc($code . ' - ' . $name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="frequency">Billed <span class="text-danger">*</span></label>
                <select class="form-select" id="frequency" name="frequency" required>
                    <?php foreach (\App\Core\Receivables\SubscriptionService::FREQUENCIES as $k => $l): ?>
                        <option value="<?= $k ?>" <?= (string) $val('frequency', 'monthly') === $k ? 'selected' : '' ?>><?= esc($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="next_bill_date"><?= $id === null ? 'First bill date' : 'Next bill date' ?> <span class="text-danger">*</span></label>
                <input class="form-control" type="date" id="next_bill_date" name="next_bill_date" required value="<?= esc((string) $val('next_bill_date')) ?>">
                <div class="form-text">Later bills fall on the same day of the month (the last day in shorter months).</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="payment_terms_days">Payment terms (days) <span class="text-danger">*</span></label>
                <input class="form-control" type="number" min="0" max="365" id="payment_terms_days" name="payment_terms_days" required value="<?= esc((string) $val('payment_terms_days', 30)) ?>">
                <div class="form-text">Due date = bill date + this many days.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="end_date">Ends on</label>
                <input class="form-control" type="date" id="end_date" name="end_date" value="<?= esc((string) $val('end_date')) ?>">
                <div class="form-text">Optional. No bills after this date.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="notes">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="5000"><?= esc((string) $val('notes')) ?></textarea>
            </div>
        </div>

        <div class="form-text mt-3">On each bill date a draft customer invoice is created in your name. You add the invoice number and PDF, then submit it for approval as usual.</div>

        <div class="d-flex flex-wrap gap-2 mt-4 align-items-center">
            <button class="btn btn-primary" type="submit"><?= $id === null ? 'Set up subscription' : 'Save changes' ?></button>
            <a class="btn btn-link" href="<?= site_url($id === null ? 'receivables/subscriptions' : 'receivables/subscriptions/' . $id) ?>">Cancel</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receivables-subscription-form.js') ?>"></script>
<?= $this->endSection() ?>
