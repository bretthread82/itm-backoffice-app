<?php $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('admin/users/' . $user['user_id'] . '/edit') ?>"><i class="bi bi-arrow-left me-1"></i>Back to user</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php if ($groupLevel): ?>
    <div class="alert alert-info"><i class="bi bi-globe2 me-1"></i><?= esc($name) ?> is a group-level user and sees every company regardless of the memberships below. Module rights such as submitting and approving still come from these rows.</div>
<?php endif; ?>
<form method="post" action="<?= site_url('admin/users/' . $user['user_id'] . '/companies') ?>" class="card">
    <?= csrf_field() ?>
    <div class="card-header">Companies for <?= esc($name) ?></div>
    <div class="table-responsive">
        <table class="table itm-table table-cards">
            <thead>
                <tr>
                    <th>Member</th><th>Company</th>
                    <?php foreach ($capabilities as $cap): ?>
                        <th title="<?= esc($cap['help'], 'attr') ?>"><?= esc($cap['label']) ?><span class="d-block small fw-normal text-body-secondary"><?= esc(ucfirst($cap['module'])) ?></span></th>
                    <?php endforeach; ?>
                    <th>Job title</th>
                </tr>
            </thead>
            <tbody>
            <?php if (! $companies): ?>
                <tr><td colspan="<?= 3 + count($capabilities) ?>" class="text-center text-body-secondary py-4">Create a company first.</td></tr>
            <?php endif; ?>
            <?php foreach ($companies as $c): $m = $memberships[(int) $c['id']] ?? null; ?>
                <tr>
                    <td data-label="Member"><input class="form-check-input" type="checkbox" name="member[<?= $c['id'] ?>]" value="1" <?= $m ? 'checked' : '' ?> aria-label="Member of <?= esc($c['name'], 'attr') ?>"></td>
                    <td data-label="Company"><?= esc($c['name']) ?> <span class="text-body-secondary small"><?= esc($c['code']) ?></span></td>
                    <?php foreach ($capabilities as $key => $cap): ?>
                        <td data-label="<?= esc(ucfirst($cap['module']) . ': ' . $cap['label'], 'attr') ?>"><input class="form-check-input" type="checkbox" name="cap[<?= $c['id'] ?>][<?= esc($key, 'attr') ?>]" value="1" <?= in_array($key, $m['capabilities'] ?? [], true) ? 'checked' : '' ?> aria-label="<?= esc(ucfirst($cap['module']) . ': ' . $cap['label'], 'attr') ?>"></td>
                    <?php endforeach; ?>
                    <td data-label="Job title"><input class="form-control form-control-sm" name="job_title[<?= $c['id'] ?>]" value="<?= esc($m['job_title'] ?? '') ?>" maxlength="120"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top"><button class="btn btn-primary">Save access</button></div>
</form>
<?= $this->endSection() ?>
