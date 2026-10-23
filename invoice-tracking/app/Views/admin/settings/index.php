<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-8 col-xl-7">
    <h1 class="h3 mb-3">Settings</h1>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Microsoft sign-in (Entra ID)</strong>
            <?= $entra['enabled'] ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?>
        </div>
        <div class="card-body">
            <p class="small text-body-secondary">
                Lets people sign in with their Microsoft work account instead of a password. It is <strong>off by default</strong>.
                Email and password sign-in always keeps working, including for administrators, whatever you choose here.
                Accounts are never created automatically: a person must already exist here with the same email address.
            </p>

            <?php if ($entra['problems'] === []): ?>
                <p class="small mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i>Configured for tenant <code><?= esc($entra['tenant']) ?></code>, application <code><?= esc($entra['clientId']) ?></code>, secret set.
                    <?= (int) $entra['linked'] ?> user<?= $entra['linked'] === 1 ? ' has' : 's have' ?> signed in this way so far.</p>
            <?php else: ?>
                <div class="alert alert-warning small">
                    <div class="fw-semibold mb-1">Not configured yet. Add these to the server's <code>.env</code> file (ITM does this), then come back:</div>
                    <ul class="mb-0"><?php foreach ($entra['problems'] as $p): ?><li><?= esc($p) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <div class="small mb-3">
                <div class="fw-semibold">Register this redirect address in the Microsoft app registration:</div>
                <code class="user-select-all"><?= esc($entra['redirectUri']) ?></code>
                <div class="text-body-secondary mt-1">Web platform, single tenant, with delegated permissions <code>openid</code>, <code>profile</code>, <code>email</code>. The address must be exactly this, over https in production.</div>
            </div>

            <form method="post" action="<?= site_url('admin/settings/microsoft') ?>" class="d-flex flex-wrap align-items-center gap-3">
                <?= csrf_field() ?>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="entra-enabled" name="enabled" value="1" <?= $entra['enabled'] ? 'checked' : '' ?> <?= $entra['problems'] !== [] && ! $entra['enabled'] ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="entra-enabled">Show "Sign in with Microsoft" on the login page</label>
                </div>
                <button class="btn btn-primary btn-sm" type="submit" <?= $entra['problems'] !== [] && ! $entra['enabled'] ? 'disabled' : '' ?>>Save</button>
            </form>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header"><strong>Group logo</strong></div>
        <div class="card-body">
            <p class="small text-body-secondary">Shown on the dashboard when all companies are selected, and for any company that has no logo of its own. Company logos are set on each company.</p>
            <?php if ($hasGroupLogo): ?><div class="mb-2"><img src="<?= site_url('logo/group') ?>" alt="Group logo" style="max-height:64px;max-width:240px"></div><?php endif; ?>
            <form method="post" action="<?= site_url('admin/settings/logo') ?>" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2">
                <?= csrf_field() ?>
                <input class="form-control w-auto" type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-label="Group logo file">
                <button class="btn btn-primary btn-sm" type="submit">Upload</button>
                <?php if ($hasGroupLogo): ?><button class="btn btn-outline-danger btn-sm" type="submit" name="remove_logo" value="1">Remove</button><?php endif; ?>
            </form>
            <div class="form-text">PNG, JPG or WebP, up to 2 MB.</div>
        </div>
    </div>
    <form method="post" action="<?= site_url('admin/settings') ?>" class="card card-body">
        <?= csrf_field() ?>

        <h2 class="h6">Email</h2>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="mailFromEmail">Sender address</label>
                <input class="form-control" type="email" id="mailFromEmail" name="mailFromEmail" required value="<?= esc(old('mailFromEmail', $s['mailFromEmail'])) ?>">
                <div class="form-text">Its domain must be authenticated in SendGrid (SPF/DKIM) or mail may be rejected or land in spam.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="mailFromName">Sender name</label>
                <input class="form-control" id="mailFromName" name="mailFromName" required maxlength="100" value="<?= esc(old('mailFromName', $s['mailFromName'])) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="mailReplyTo">Reply-to <span class="text-body-secondary">(optional)</span></label>
                <input class="form-control" type="email" id="mailReplyTo" name="mailReplyTo" value="<?= esc(old('mailReplyTo', $s['mailReplyTo'])) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="digestHour">Daily summary hour (0-23)</label>
                <input class="form-control" type="number" min="0" max="23" id="digestHour" name="digestHour" required value="<?= esc(old('digestHour', $s['digestHour'])) ?>">
                <div class="form-text">For reference: the actual time is set by the server's scheduled job.</div>
            </div>
        </div>

        <h2 class="h6">Invoices</h2>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label" for="maxUploadMb">Largest upload (MB)</label>
                <input class="form-control" type="number" min="1" max="100" id="maxUploadMb" name="maxUploadMb" required value="<?= esc(old('maxUploadMb', $s['maxUploadMb'])) ?>">
                <div class="form-text">The web server's own upload limit must be at least this.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="defaultCurrency">Default currency</label>
                <input class="form-control text-uppercase" id="defaultCurrency" name="defaultCurrency" maxlength="3" required value="<?= esc(old('defaultCurrency', $s['defaultCurrency'])) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="retentionDays">Keep files (days)</label>
                <input class="form-control" type="number" min="1" id="retentionDays" name="retentionDays" required value="<?= esc(old('retentionDays', $s['retentionDays'])) ?>">
                <div class="form-text">After completion and once saved to SharePoint.</div>
            </div>
        </div>

        <div><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
</div></div>
<?= $this->endSection() ?>
