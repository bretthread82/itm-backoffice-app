<?php
$v = static fn (string $k, mixed $d = '') => old($k, $c[$k] ?? $d);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-xl-9">
    <a class="small" href="<?= site_url('admin/sharepoint') ?>"><i class="bi bi-arrow-left"></i> Connections</a>
    <h1 class="h3 mt-1 mb-3"><?= esc($title) ?></h1>

    <?php if ($id !== null): ?>
        <div class="card card-body mb-3">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span>Status:</span>
                <?php if ((int) $c['enabled'] === 1): ?><span class="badge text-bg-success">On</span><?php else: ?><span class="badge text-bg-secondary">Off</span><?php endif; ?>
                <?php if ($c['last_error']): ?>
                    <span class="badge text-bg-danger">Last test or send failed</span> <span class="small text-danger"><?= esc($c['last_error']) ?></span>
                <?php elseif ($c['last_tested_at']): ?>
                    <span class="badge text-bg-success">Tested OK <?= esc(time_ago($c['last_tested_at'])) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-warning">Not tested since the settings last changed</span>
                <?php endif; ?>
                <?php if ($companies !== []): ?><span class="small text-body-secondary ms-auto">Used by <?= esc(implode(', ', array_column($companies, 'code'))) ?></span><?php endif; ?>
            </div>
            <form method="post" action="<?= site_url('admin/sharepoint/' . $id . '/test') ?>" class="mt-2">
                <?= csrf_field() ?>
                <button class="btn btn-outline-primary btn-sm" type="submit" <?= $missing !== [] ? 'disabled' : '' ?>><i class="bi bi-plug me-1"></i>Test connection</button>
                <span class="small text-body-secondary ms-2">Checks the sign-in, the site, the library, the list and every mapped column. Changes nothing in SharePoint.</span>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= site_url($id === null ? 'admin/sharepoint' : 'admin/sharepoint/' . $id) ?>" class="card card-body mb-3" autocomplete="off">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" maxlength="150" required value="<?= esc($v('name')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="site_url">SharePoint site address</label>
                <input class="form-control" id="site_url" name="site_url" maxlength="500" placeholder="https://contoso.sharepoint.com/sites/InvoiceApprovalandTracking" value="<?= esc($v('site_url')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="tenant_id">Tenant ID</label>
                <input class="form-control" id="tenant_id" name="tenant_id" maxlength="100" value="<?= esc($v('tenant_id')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="client_id">Application (client) ID</label>
                <input class="form-control" id="client_id" name="client_id" maxlength="100" value="<?= esc($v('client_id')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="client_secret">Client secret</label>
                <input class="form-control" type="password" id="client_secret" name="client_secret" autocomplete="new-password" placeholder="<?= $hasSecret ? '●●●●●●●● saved (leave blank to keep it)' : '' ?>">
                <div class="form-text">Stored encrypted and never shown again.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="library_name">Document library</label>
                <input class="form-control" id="library_name" name="library_name" maxlength="200" value="<?= esc($v('library_name', 'Documents')) ?>">
                <div class="form-text">The PDF is saved here. "Documents" is what SharePoint calls "Shared Documents".</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="folder">Folder in the library</label>
                <input class="form-control" id="folder" name="folder" maxlength="200" value="<?= esc($v('folder', 'Invoices')) ?>">
                <div class="form-text">Files go in <em>folder / company / year</em>.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="list_name">List for the invoice record</label>
                <input class="form-control" id="list_name" name="list_name" maxlength="200" value="<?= esc($v('list_name')) ?>">
                <div class="form-text">Leave empty to save only the PDF.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="field_mapping">Column mapping (JSON)</label>
                <textarea class="form-control font-monospace" id="field_mapping" name="field_mapping" rows="6" placeholder='<?= esc($defaultMapping) ?>'><?= esc($v('field_mapping')) ?></textarea>
                <div class="form-text">
                    Which invoice value goes in which SharePoint column, using each column's <strong>internal name</strong>, for example
                    <code>{"Title": "invoice_number", "Supplier": "supplier", "Amount": "amount", "DueDate": "due_date"}</code>.
                    Empty means <code><?= esc($defaultMapping) ?></code> only. Testing the connection checks that every column exists.
                </div>
                <details class="small mt-1"><summary>Invoice values you can use</summary>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($mappingKeys as $k => $label): ?><li><code><?= esc($k) ?></code> · <?= esc($label) ?></li><?php endforeach; ?>
                    </ul>
                </details>
            </div>
            <div class="col-12">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_default" name="is_default" value="1" <?= (int) $v('is_default', 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_default">Default connection (used by companies that have none chosen)</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= (int) $v('enabled', 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enabled"><strong>Switched on</strong>: completed invoices are sent to this SharePoint site</label>
                </div>
                <div class="form-text">It can only be switched on after a successful test of its current settings. Changing any setting needs a new test.</div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('admin/sharepoint') ?>">Back</a>
        </div>
    </form>

    <?php if ($id !== null): ?>
        <form method="post" action="<?= site_url('admin/sharepoint/' . $id . '/delete') ?>" onsubmit="return confirm('Delete this connection?')" class="mb-4">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger btn-sm" type="submit">Delete connection</button>
            <span class="small text-body-secondary ms-2">Only possible when no company uses it.</span>
        </form>
    <?php endif; ?>

    <details class="small text-body-secondary mb-4">
        <summary>What to set up in Microsoft first (ITM does this)</summary>
        <ol class="mt-2">
            <li>Register an app in Entra ID and create a client secret.</li>
            <li>Give it the <strong>Sites.Selected</strong> application permission (admin consent), then grant it <strong>write</strong> access to just this one site. It cannot see anything else in SharePoint.</li>
            <li>Create the list (and a document library if needed), then enter its settings here and press Test.</li>
        </ol>
    </details>
</div></div>
<?= $this->endSection() ?>
