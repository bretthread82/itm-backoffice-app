<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-8 col-xl-7">
    <h1 class="h3 mb-1">Notification settings</h1>
    <p class="text-body-secondary">The bell in the top bar always shows your alerts. Choose which ones are also emailed to you. Alerts for HIGH priority invoice types (a new, resubmitted or returned invoice waiting for you, and its reminders and escalations) are always emailed and cannot be switched off.</p>

    <form method="post" action="<?= site_url('account/notifications') ?>" class="card">
        <?= csrf_field() ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($events as $event => $label): $on = ! isset($prefs[$event]) || (int) $prefs[$event]['email'] === 1; ?>
                <li class="list-group-item">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="ev-<?= esc($event) ?>" name="email[<?= esc($event) ?>]" value="1" <?= $on ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ev-<?= esc($event) ?>">
                            <?= esc($label) ?>
                            <?php if ($event === 'digest'): ?><span class="text-body-secondary small d-block">One email each morning, only when something needs your attention. Turning it off also stops the daily bell summary.</span><?php endif; ?>
                        </label>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="card-body"><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
</div></div>
<?= $this->endSection() ?>
