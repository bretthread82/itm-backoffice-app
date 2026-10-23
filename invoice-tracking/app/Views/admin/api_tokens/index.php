<?php
/**
 * @var list<array<string, mixed>> $tokens
 * @var list<array<string, mixed>> $users
 * @var int $me
 * @var array<int, string> $days
 * @var string|null $newToken
 * @var string $newFor
 */
$now = date('Y-m-d H:i:s');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<h1 class="h3 mb-1">API tokens</h1>
<p class="text-body-secondary">
    Only administrators can make API tokens, for themselves or for any active user. A token is read-only and can only ever see what its
    owner can see, and it stops working if the owner is made inactive. Revoking one takes effect straight away.
</p>

<?php if ($newToken !== null): ?>
    <div class="alert alert-success">
        <div class="fw-semibold mb-1"><i class="bi bi-key-fill me-1"></i>New token for <?= esc($newFor) ?>. Copy it now: it is shown only this once.</div>
        <code class="user-select-all d-block p-2 bg-body-tertiary rounded text-break" id="new-token"><?= esc($newToken) ?></code>
        <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="copy-token"><i class="bi bi-clipboard me-1"></i>Copy</button>
    </div>
<?php endif; ?>

<form method="post" action="<?= site_url('admin/api-tokens') ?>" class="card card-body mb-4">
    <?= csrf_field() ?>
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="token-user">Token for</label>
            <select class="form-select" id="token-user" name="user_id" required>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) old('user_id', $me) === (int) $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?><?= (int) $u['id'] === $me ? ' (me)' : '' ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="token-name">Name</label>
            <input class="form-control" id="token-name" name="name" maxlength="100" required placeholder="e.g. Power BI reporting" value="<?= esc(old('name')) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="token-days">Lasts for</label>
            <select class="form-select" id="token-days" name="days">
                <?php foreach ($days as $d => $label): ?><option value="<?= $d ?>" <?= (int) old('days', 365) === $d ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Create token</button></div>
    </div>
    <div class="form-text mt-2">Up to 10 tokens per user. Read-only access to invoices, approvals and reports.</div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-cards">
            <thead><tr><th>Owner</th><th>Token name</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t):
                $expired = $t['expires'] !== null && $t['expires'] < $now; ?>
                <tr>
                    <td data-label="Owner"><?= esc($t['owner']) ?> <?= (int) $t['active'] ? '' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                    <td data-label="Token name"><?= esc($t['name']) ?> <?= $expired ? '<span class="badge text-bg-secondary">Expired</span>' : '' ?></td>
                    <td data-label="Created" class="small"><?= esc((string) $t['created_at']) ?></td>
                    <td data-label="Last used" class="small"><?= $t['last_used_at'] ? esc((string) $t['last_used_at']) : '<span class="text-body-secondary">never</span>' ?></td>
                    <td data-label="Expires" class="small"><?= $t['expires'] ? esc((string) $t['expires']) : '—' ?></td>
                    <td class="actions text-end">
                        <form method="post" action="<?= site_url('admin/api-tokens/' . $t['id'] . '/revoke') ?>" onsubmit="return confirm('Revoke this token?')">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($tokens === []): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No API tokens have been made.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var btn = document.getElementById('copy-token'), code = document.getElementById('new-token');
    if (!btn || !code || !navigator.clipboard) { return; }
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(code.textContent.trim()).then(function () { btn.textContent = 'Copied'; });
    });
})();
</script>
<?= $this->endSection() ?>
