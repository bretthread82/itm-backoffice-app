<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-3">Upload complete</h1>
<div class="card card-body mb-3">
    <p class="mb-1"><strong><?= (int) $result['created'] ?></strong> users created, <strong><?= (int) $result['updated'] ?></strong> updated.</p>
    <?php if ($invite): ?>
        <p class="mb-0"><?= (int) $invited ?> invitations sent<?= $failed ? ', ' . (int) $failed . ' could not be sent (use "Resend invitation" on the user)' : '' ?>.</p>
    <?php elseif ($result['created'] > 0): ?>
        <p class="mb-0 text-body-secondary">New users have not been emailed yet. Use "Invite all pending" on the Users page when you are ready.</p>
    <?php endif; ?>
</div>
<a class="btn btn-primary" href="<?= site_url('admin/users') ?>">Go to users</a>
<?= $this->endSection() ?>
