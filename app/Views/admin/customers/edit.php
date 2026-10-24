<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/customers') ?>"><i class="bi bi-arrow-left me-1"></i>All customers</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $v = static fn (string $f): string => esc((string) old($f, $customer[$f] ?? '')); ?>
<div class="row g-4">
    <div class="col-lg-8">
        <form method="post" action="<?= site_url('admin/customers/' . $customer['id']) ?>" class="card card-body">
            <?= csrf_field() ?>
            <h2 class="h6">Details</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="code">Customer code <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" id="code" name="code" maxlength="50" required value="<?= $v('code') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="name">Customer name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" id="name" name="name" maxlength="200" required value="<?= $v('name') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="legal_name">Legal name</label>
                    <input class="form-control" id="legal_name" name="legal_name" maxlength="200" value="<?= $v('legal_name') ?>">
                    <div class="form-text">If different from the name above.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tax_id">Tax ID</label>
                    <input class="form-control" id="tax_id" name="tax_id" maxlength="50" value="<?= $v('tax_id') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" type="email" id="email" name="email" maxlength="255" value="<?= $v('email') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Phone</label>
                    <input class="form-control" id="phone" name="phone" maxlength="50" value="<?= $v('phone') ?>">
                </div>
            </div>

            <h2 class="h6 mt-4">Billing address</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="address_line1">Address line 1</label>
                    <input class="form-control" id="address_line1" name="address_line1" maxlength="200" value="<?= $v('address_line1') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="address_line2">Address line 2</label>
                    <input class="form-control" id="address_line2" name="address_line2" maxlength="200" value="<?= $v('address_line2') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="city">City</label>
                    <input class="form-control" id="city" name="city" maxlength="100" value="<?= $v('city') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="region">Region</label>
                    <input class="form-control" id="region" name="region" maxlength="100" value="<?= $v('region') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="postal_code">Postal code</label>
                    <input class="form-control" id="postal_code" name="postal_code" maxlength="20" value="<?= $v('postal_code') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="country">Country</label>
                    <input class="form-control" id="country" name="country" maxlength="100" value="<?= $v('country') ?>">
                </div>
            </div>

            <h2 class="h6 mt-4">Defaults</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="payment_terms_days">Payment terms (days)</label>
                    <input class="form-control" type="number" min="0" max="365" id="payment_terms_days" name="payment_terms_days" value="<?= $v('payment_terms_days') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="default_currency">Currency</label>
                    <?php
                    $currencies = invoice_currencies();
                    $chosen     = strtoupper((string) old('default_currency', $customer['default_currency'] ?? ''));
                    if ($chosen !== '' && ! isset($currencies[$chosen])) {
                        $currencies = [$chosen => $chosen] + $currencies;   // keep an older code selectable
                    }
                    ?>
                    <select class="form-select" id="default_currency" name="default_currency">
                        <option value="">Platform default</option>
                        <?php foreach ($currencies as $code => $cname): ?>
                            <option value="<?= esc($code) ?>" <?= $code === $chosen ? 'selected' : '' ?>><?= esc($code . ' - ' . $cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Used on new invoices for this customer.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="5000"><?= $v('notes') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" value="1" id="active" <?= (int) $customer['active'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Active</label>
                    </div>
                    <div class="form-text">Switching a customer off hides it from pick-lists. Existing records keep it.</div>
                </div>
            </div>
            <div class="mt-3"><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Contacts</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($contacts as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div><?= esc($c['name']) ?> <?php if ((int) $c['is_primary'] === 1): ?><span class="badge-status st-info">Primary</span><?php endif; ?> <?php if ((int) $c['is_notify'] === 1): ?><span class="badge-status st-success"><i class="bi bi-bell me-1"></i>Notifications</span><?php endif; ?></div>
                            <?php if ($c['role']): ?><div class="small text-body-secondary"><?= esc($c['role']) ?></div><?php endif; ?>
                            <?php if ($c['email']): ?><div class="small"><?= esc($c['email']) ?></div><?php endif; ?>
                            <?php if ($c['phone']): ?><div class="small"><?= esc($c['phone']) ?></div><?php endif; ?>
                        </div>
                        <div class="d-flex gap-1">
                            <?php if ((int) $c['is_notify'] === 1): ?>
                                <form method="post" action="<?= site_url('admin/customers/' . $customer['id'] . '/contacts/clear-notify') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Stop sending notifications to <?= esc($c['name']) ?>" title="Stop sending notifications"><i class="bi bi-bell-slash"></i></button>
                                </form>
                            <?php elseif ($c['email']): ?>
                                <form method="post" action="<?= site_url('admin/customers/' . $customer['id'] . '/contacts/' . $c['id'] . '/notify') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Send notifications to <?= esc($c['name']) ?>" title="Send notifications to this contact"><i class="bi bi-bell"></i></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= site_url('admin/customers/' . $customer['id'] . '/contacts/' . $c['id'] . '/delete') ?>" data-confirm="Remove this contact?">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove <?= esc($c['name']) ?>"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($contacts === []): ?><li class="list-group-item text-body-secondary">No contacts yet.</li><?php endif; ?>
            </ul>
            <form method="post" action="<?= site_url('admin/customers/' . $customer['id'] . '/contacts') ?>" class="card-body border-top">
                <?= csrf_field() ?>
                <h2 class="h6">Add a contact</h2>
                <div class="mb-3">
                    <label class="form-label" for="c-name">Name</label>
                    <input class="form-control" id="c-name" name="name" maxlength="200" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="c-role">Role</label>
                    <input class="form-control" id="c-role" name="role" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="c-email">Email</label>
                    <input class="form-control" type="email" id="c-email" name="email" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="c-phone">Phone</label>
                    <input class="form-control" id="c-phone" name="phone" maxlength="50">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="c-primary">
                    <label class="form-check-label" for="c-primary">Primary contact</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_notify" value="1" id="c-notify">
                    <label class="form-check-label" for="c-notify">Send notifications to this contact</label>
                    <div class="form-text">Needs an email address. Only one contact receives them.</div>
                </div>
                <button class="btn btn-outline-primary" type="submit">Add contact</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
