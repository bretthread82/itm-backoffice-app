<?php
$ready = $config->isReady();
$badge = static fn (bool $ok, string $yes, string $no) => '<span class="badge text-bg-' . ($ok ? 'success' : 'secondary') . '">' . ($ok ? $yes : $no) . '</span>';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php if (! $config->isConfigured()): ?>
    <div class="alert alert-info">
        <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Microsoft Entra is optional and currently not set up.</div>
        The application works fully without it. When you are ready, follow the setup steps below and add your details to <code>.env</code>.
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Status</h2>
                <dl class="row mb-3 small">
                    <dt class="col-5">Switched on</dt><dd class="col-7"><?= $badge($config->enabled, 'Yes', 'No') ?> <code class="ms-1">entra.enabled</code></dd>
                    <dt class="col-5">Configured</dt><dd class="col-7"><?= $badge($config->isConfigured(), 'Yes', 'No') ?></dd>
                    <dt class="col-5">Tenant ID</dt><dd class="col-7"><?= esc($masked['tenant']) ?></dd>
                    <dt class="col-5">Client ID</dt><dd class="col-7"><?= esc($masked['client']) ?></dd>
                    <dt class="col-5">Client secret</dt><dd class="col-7"><?= esc($masked['secret']) ?></dd>
                    <dt class="col-5">Sign-in</dt><dd class="col-7"><?= $badge($ready, 'Available', 'Hidden') ?></dd>
                    <dt class="col-5">Create accounts on sign-in</dt><dd class="col-7"><?= $config->jitProvisioning ? 'Yes' : 'No' ?></dd>
                    <dt class="col-5">Link existing accounts by email</dt><dd class="col-7"><?= $config->linkExistingByEmail ? 'Yes' : 'No' ?></dd>
                    <dt class="col-5">Sync creates accounts</dt><dd class="col-7"><?= $config->syncCreatesUsers ? 'Yes' : 'No' ?></dd>
                    <dt class="col-5">Sync deactivates</dt><dd class="col-7"><?= $config->syncDeactivates ? 'Yes (limit ' . (int) $config->deactivateLimitPercent . '%)' : 'No' ?></dd>
                </dl>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" action="<?= site_url('admin/entra/test') ?>"><?= csrf_field() ?>
                        <button class="btn btn-outline-primary" <?= $config->isConfigured() ? '' : 'disabled' ?>><i class="bi bi-plug me-1"></i>Test connection</button>
                    </form>
                    <form method="post" action="<?= site_url('admin/entra/sync') ?>" data-confirm="Run a directory sync now?"><?= csrf_field() ?>
                        <button class="btn btn-primary" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat me-1"></i>Sync now</button>
                    </form>
                </div>
                <?php if (! $ready): ?><div class="form-text mt-2">Buttons unlock once Entra is switched on and configured.</div><?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Setup steps</h2>
                <ol class="small ps-3 mb-0">
                    <li class="mb-2">In the Entra admin centre, go to <strong>App registrations → New registration</strong>. Choose <em>Accounts in this organizational directory only</em>.</li>
                    <li class="mb-2">Add a <strong>Web</strong> redirect URI exactly matching:<br><code class="user-select-all"><?= esc($config->callbackUrl()) ?></code></li>
                    <li class="mb-2">Under <strong>API permissions</strong>, add Microsoft Graph <em>application</em> permissions <code>User.Read.All</code> and <code>GroupMember.Read.All</code>, then <strong>Grant admin consent</strong>. (Sign-in itself uses the delegated <code>openid profile email</code> permissions.)</li>
                    <li class="mb-2">Under <strong>Certificates &amp; secrets</strong>, create a client secret.</li>
                    <li class="mb-2">Put the <em>Directory (tenant) ID</em>, <em>Application (client) ID</em> and secret in <code>.env</code> (<code>entra.tenantId</code>, <code>entra.clientId</code>, <code>entra.clientSecret</code>) and set <code>entra.enabled = true</code>.</li>
                    <li class="mb-2">Come back here and press <strong>Test connection</strong>, then add group mappings and <strong>Sync now</strong>.</li>
                    <li>Schedule <code>php spark entra:sync</code> in cron (for example every 4 hours).</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-1">Group → role mapping</h2>
                <p class="small text-body-secondary">Members of an Entra group receive the role on each sync, and lose it when removed from the group. Roles you assign by hand that are not listed here are never removed. Super Admin cannot be mapped.</p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle table-stack">
                        <thead><tr><th>Entra group</th><th>Role</th><th></th></tr></thead>
                        <tbody>
                        <?php if (! $mappings): ?><tr><td colspan="3" class="text-body-secondary">No mappings. New Entra users receive the default role(s).</td></tr><?php endif; ?>
                        <?php foreach ($mappings as $m): ?>
                            <tr>
                                <td data-label="Group"><?= esc($m['group_name'] ?: 'Unnamed group') ?><div class="small text-body-secondary text-break"><?= esc($m['entra_group_id']) ?></div></td>
                                <td data-label="Role"><?= esc($m['role_name']) ?></td>
                                <td class="text-end">
                                    <form method="post" action="<?= site_url('admin/entra/mappings/delete') ?>" data-confirm="Remove this mapping?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="entra_group_id" value="<?= esc($m['entra_group_id'], 'attr') ?>">
                                        <input type="hidden" name="role_id" value="<?= (int) $m['role_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="post" action="<?= site_url('admin/entra/mappings') ?>" class="row g-2 align-items-end border-top pt-3">
                    <?= csrf_field() ?>
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="entra_group_id">Group object ID</label>
                        <input class="form-control" id="entra_group_id" name="entra_group_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" value="<?= esc(old('entra_group_id')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="group_name">Label</label>
                        <input class="form-control" id="group_name" name="group_name" placeholder="e.g. Finance team" maxlength="200" value="<?= esc(old('group_name')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="role_id">Role</label>
                        <select class="form-select" id="role_id" name="role_id" required>
                            <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= esc($r['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><button class="btn btn-outline-primary w-100">Add</button></div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-body-secondary mb-3">Recent syncs</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 table-stack">
                        <thead><tr><th>Started</th><th>Created</th><th>Updated</th><th>Deactivated</th><th>Result</th></tr></thead>
                        <tbody>
                        <?php if (! $runs): ?><tr><td colspan="5" class="text-body-secondary">No syncs have run.</td></tr><?php endif; ?>
                        <?php foreach ($runs as $r): ?>
                            <tr>
                                <td data-label="Started" class="text-nowrap"><?= esc(local_time($r['started_at'], 'Y-m-d H:i')) ?></td>
                                <td data-label="Created"><?= (int) $r['created'] ?></td>
                                <td data-label="Updated"><?= (int) $r['updated'] ?></td>
                                <td data-label="Deactivated"><?= (int) $r['deactivated'] ?></td>
                                <td data-label="Result" class="small">
                                    <?php if ($r['errors']): ?><span class="text-danger"><?= esc(mb_strimwidth($r['errors'], 0, 120, '…')) ?></span>
                                    <?php elseif ($r['finished_at']): ?><span class="text-success">OK</span>
                                    <?php else: ?><span class="text-body-secondary">Running / interrupted</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
