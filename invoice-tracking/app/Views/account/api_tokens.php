<?php
/**
 * @var list<\CodeIgniter\Shield\Entities\AccessToken> $tokens
 * @var bool $isAdmin
 */
$now = date('Y-m-d H:i:s');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">API tokens</h1>
<p class="text-body-secondary">
    A token lets another system read invoice data on your behalf: only what <strong>you</strong> can see, and read-only.
    Treat it like a password. Use it over https as <code>Authorization: Bearer &lt;token&gt;</code>.
    <a href="<?= site_url('api/docs') ?>">API documentation</a>
</p>

<div class="alert alert-info">
    API tokens are issued by an administrator<?= $isAdmin ? ': <a href="' . site_url('admin/api-tokens') . '">create one under Administration</a>' : '. Ask one to make a token for you' ?>.
    Tokens you already have keep working until they expire, and you can revoke them here.
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t):
                $expired = $t->expires !== null && $t->expires->toDateTimeString() < $now; ?>
                <tr>
                    <td data-label="Name"><?= esc($t->name) ?> <?= $expired ? '<span class="badge text-bg-secondary">Expired</span>' : '' ?></td>
                    <td data-label="Created" class="small"><?= esc($t->created_at?->toDateTimeString() ?? '') ?></td>
                    <td data-label="Last used" class="small"><?= $t->last_used_at ? esc($t->last_used_at->toDateTimeString()) : '<span class="text-body-secondary">never</span>' ?></td>
                    <td data-label="Expires" class="small"><?= $t->expires ? esc($t->expires->toDateTimeString()) : '—' ?></td>
                    <td class="actions text-end">
                        <form method="post" action="<?= site_url('account/api-tokens/' . $t->id . '/revoke') ?>" onsubmit="return confirm('Revoke this token? Anything using it stops working.')">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($tokens === []): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">You have no API tokens.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
