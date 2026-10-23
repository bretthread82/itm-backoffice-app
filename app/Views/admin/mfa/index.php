<?php
$meta = [
    'totp'  => ['Authenticator app', 'Time-based codes from an app such as Microsoft Authenticator, Google Authenticator or Authy.', 'phone'],
    'email' => ['Email code', 'A short-lived one-time code emailed at sign-in. Convenient, but weaker: it shares a mailbox with password reset.', 'envelope'],
    'duo'   => ['Duo', 'Approve sign-ins with Duo (Universal Prompt). Duo handles its own enrolment.', 'shield-lock'],
];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-12 col-xl-8">
        <form method="post" action="<?= site_url('admin/mfa') ?>" class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <div class="card-body p-4">
                <div class="form-check form-switch fs-5 mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= old('enabled', $enabled) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enabled">Two-step verification is <strong><?= $enabled ? 'on' : 'off' ?></strong></label>
                </div>
                <p class="small text-body-secondary mb-4">When off, nobody is asked for a second step and any enrolments are kept for later. Microsoft Entra sign-ins are not asked either; Entra enforces its own MFA.</p>

                <h2 class="h6 text-uppercase text-body-secondary mb-3">Methods people can use</h2>
                <?php foreach ($meta as $type => [$name, $desc, $icon]): $m = $methods[$type]; ?>
                    <div class="d-flex gap-3 align-items-start border rounded p-3 mb-2">
                        <i class="bi bi-<?= $icon ?> fs-3 text-primary"></i>
                        <div class="flex-fill">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="m_<?= $type ?>" name="<?= $type ?>" value="1"
                                       <?= old($type, $m['on']) ? 'checked' : '' ?> <?= $m['reason'] ? 'disabled' : '' ?>>
                                <label class="form-check-label fw-semibold" for="m_<?= $type ?>"><?= esc($name) ?></label>
                                <span class="badge text-bg-light border ms-1"><?= (int) $m['users'] ?> enrolled</span>
                            </div>
                            <div class="small text-body-secondary"><?= esc($desc) ?></div>
                            <?php if ($m['reason']): ?><div class="small text-warning-emphasis mt-1"><i class="bi bi-info-circle me-1"></i><?= esc($m['reason']) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-1">Who must use it</h2>
                <p class="small text-body-secondary">People in these roles are made to set up a method at their next sign-in. Everyone else may opt in from their profile; once they do, they are asked every time.</p>
                <div class="row g-2 mb-1">
                    <?php $sel = array_map('intval', (array) old('required_roles', $required)); foreach ($roles as $r): ?>
                        <div class="col-sm-6">
                            <div class="form-check border rounded p-2 ps-5">
                                <input class="form-check-input" type="checkbox" name="required_roles[]" id="rr<?= $r['id'] ?>" value="<?= $r['id'] ?>" <?= in_array((int) $r['id'], $sel, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rr<?= $r['id'] ?>"><?= esc($r['name']) ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($notEnrolled): ?><div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= (int) $notEnrolled ?> required user<?= $notEnrolled === 1 ? '' : 's' ?> still need to enrol.</div><?php endif; ?>

                <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-1">Remember this device</h2>
                <div class="row g-2 align-items-center">
                    <div class="col-auto"><input type="number" class="form-control w-6rem" id="remember_days" name="remember_days" min="0" max="90" value="<?= (int) old('remember_days', $remember) ?>"></div>
                    <div class="col-auto"><label for="remember_days" class="form-text mb-0">days (0 = always ask). Turning this down, or turning a method off, signs out every remembered device.</label></div>
                </div>
            </div>
            <div class="card-footer bg-transparent p-3 text-end"><button class="btn btn-primary">Save settings</button></div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h2 class="h6 text-uppercase text-body-secondary">Good to know</h2>
            <ul class="small ps-3 mb-0">
                <li class="mb-2">Users keep <strong>recovery codes</strong> for lost devices. An administrator can also reset someone's two-step setup from their user page.</li>
                <li class="mb-2">Enrolled factors are protected with the application's encryption key. Keep it backed up; without it authenticator secrets cannot be read.</li>
                <li>Duo redirect URL to register in the Duo Admin Panel:<br><code class="user-select-all"><?= esc($duoUrl) ?></code></li>
            </ul>
        </div></div>

        <div class="card border-0 shadow-sm"><div class="card-body">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Recent activity</h2>
            <ul class="list-unstyled small mb-0">
                <?php if (! $events): ?><li class="text-body-secondary">Nothing yet.</li><?php endif; ?>
                <?php foreach ($events as $e): ?>
                    <li class="d-flex justify-content-between py-1"><code><?= esc($e['action']) ?></code><span class="text-body-secondary"><?= esc(local_time($e['created_at'], 'j M H:i')) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
