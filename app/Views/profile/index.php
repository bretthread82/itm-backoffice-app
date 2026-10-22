<?php
$isEntra = ($me['source'] ?? 'local') === 'entra';
$v = static fn (string $f) => old($f, $me[$f] ?? '');
$hasPending = array_column(array_filter($requests, static fn ($r) => $r['status'] === 'pending'), 'field');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->include('profile/_tabs') ?>

<?php if ($isEntra): ?>
    <div class="alert alert-info"><i class="bi bi-microsoft me-1"></i>Your name and work details are managed by Microsoft Entra. Ask your administrator to update them in Entra.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm text-center mb-4">
            <div class="card-body p-4">
                <?= avatar($me, 'avatar-xl mb-3') ?>
                <h2 class="h5 mb-0"><?= esc($me['display_name'] ?: $me['first_name'] . ' ' . $me['last_name']) ?></h2>
                <div class="text-body-secondary small"><?= esc($me['email']) ?></div>

                <form method="post" action="<?= site_url('profile/avatar') ?>" enctype="multipart/form-data" class="mt-3">
                    <?= csrf_field() ?>
                    <input class="form-control form-control-sm mb-2" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required aria-label="Choose a photo">
                    <button class="btn btn-sm btn-outline-primary">Upload photo</button>
                </form>
                <?php if ($me['avatar_path']): ?>
                    <form method="post" action="<?= site_url('profile/avatar/remove') ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-link text-danger">Remove photo</button>
                    </form>
                <?php endif; ?>
                <div class="small text-body-secondary mt-2">JPG, PNG or WebP, up to 4&nbsp;MB.</div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Work details</h2>
                <dl class="mb-0 small">
                    <dt>Job title</dt><dd><?= esc($me['job_title'] ?? '—') ?></dd>
                    <dt>Department</dt><dd><?= esc($me['department'] ?? '—') ?></dd>
                    <dt>Roles</dt><dd><?= esc($me['roles'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <form method="post" action="<?= site_url('profile') ?>" class="card border-0 shadow-sm mb-4">
            <?= csrf_field() ?>
            <div class="card-body p-4">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">About me</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="display_name">Display name</label>
                        <input class="form-control" id="display_name" name="display_name" value="<?= esc($v('display_name')) ?>" <?= $isEntra ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="location">Location</label>
                        <input class="form-control" id="location" name="location" value="<?= esc($v('location')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control" id="phone" name="phone" value="<?= esc($v('phone')) ?>" autocomplete="tel">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="mobile">Mobile</label>
                        <input class="form-control" id="mobile" name="mobile" value="<?= esc($v('mobile')) ?>" autocomplete="tel">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="bio">Bio</label>
                        <textarea class="form-control" id="bio" name="bio" rows="3" maxlength="2000"><?= esc($v('bio')) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="theme">Appearance</label>
                        <select class="form-select" id="theme" name="theme">
                            <?php foreach (['auto' => 'Match my device', 'light' => 'Light', 'dark' => 'Dark'] as $k => $label): ?>
                                <option value="<?= $k ?>" <?= $v('theme') === $k ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent p-3 text-end"><button class="btn btn-primary">Save changes</button></div>
        </form>

        <?php if (! $isEntra): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 text-uppercase text-body-secondary mb-1">Request a change</h2>
                <p class="small text-body-secondary">Job title, department and manager are set by an administrator. Send a request and you will be notified of the decision.</p>

                <form method="post" action="<?= site_url('profile/requests') ?>" class="row g-2 align-items-end" id="requestForm">
                    <?= csrf_field() ?>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="field">What to change</label>
                        <select class="form-select" id="field" name="field">
                            <?php foreach ($requestable as $k => $label): ?>
                                <option value="<?= $k ?>" <?= in_array($k, $hasPending, true) ? 'disabled' : '' ?>><?= esc($label) ?><?= in_array($k, $hasPending, true) ? ' (pending)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small mb-1" for="new_value_text">New value</label>
                        <input class="form-control" id="new_value_text" data-for="job_title" name="new_value" maxlength="120" placeholder="e.g. Senior Accountant">
                        <select class="form-select d-none" id="new_value_dept" data-for="department_id" disabled>
                            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= esc($d['name']) ?></option><?php endforeach; ?>
                        </select>
                        <select class="form-select d-none" id="new_value_mgr" data-for="manager_id" disabled>
                            <?php foreach ($managers as $m): ?><option value="<?= $m['user_id'] ?>"><?= esc($m['display_name'] ?: $m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="reason">Reason (optional)</label>
                        <input class="form-control" id="reason" name="reason" maxlength="500">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-primary">Send request</button></div>
                </form>

                <?php if ($requests): ?>
                    <hr>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($requests as $r):
                            $badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$r['status']]; ?>
                            <li class="d-flex justify-content-between align-items-center py-1">
                                <span><?= esc($requestable[$r['field']] ?? $r['field']) ?> <span class="text-body-secondary">· <?= esc(date('j M', strtotime($r['created_at']))) ?></span></span>
                                <span>
                                    <span class="badge text-bg-<?= $badge ?>"><?= ucfirst($r['status']) ?></span>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form class="d-inline" method="post" action="<?= site_url('profile/requests/' . $r['id'] . '/cancel') ?>">
                                            <?= csrf_field() ?><button class="btn btn-link btn-sm p-0 ms-2">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/profile.js') ?>"></script>
<?= $this->endSection() ?>
