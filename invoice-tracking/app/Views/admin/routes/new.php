<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row"><div class="col-lg-7 col-xl-6">
    <h1 class="h3 mb-3">New route</h1>
    <form method="post" action="<?= site_url('admin/routes') ?>" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="company_id">Company</label>
            <select class="form-select" id="company_id" name="company_id" required>
                <option value="">— choose —</option>
                <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" <?= (string) old('company_id') === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?> · <?= esc($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php $oldChoice = (string) old('type_choice', $types === [] ? 'other' : ''); ?>
        <div class="mb-3">
            <label class="form-label" for="type_choice">Invoice type</label>
            <select class="form-select" id="type_choice" name="type_choice" required>
                <option value="">— choose —</option>
                <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>" <?= $oldChoice === (string) $t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option><?php endforeach; ?>
                <option value="other" <?= $oldChoice === 'other' ? 'selected' : '' ?>>Other (custom type)…</option>
            </select>
            <div class="form-text">Default types are added to the company if it does not have them yet.</div>
        </div>
        <div class="mb-3" id="custom-type" <?= $oldChoice === 'other' ? '' : 'hidden' ?>>
            <label class="form-label" for="type_name">Custom invoice type</label>
            <input class="form-control" id="type_name" name="type_name" maxlength="200" value="<?= esc(old('type_name')) ?>" <?= $oldChoice === 'other' ? 'required' : '' ?>>
            <div class="form-text">If the type does not exist for this company it is created.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="variant">Variant</label>
            <select class="form-select" id="variant" name="variant">
                <?php foreach ($variants as $v): ?><option value="<?= esc($v['variant_key']) ?>" <?= old('variant') === $v['variant_key'] ? 'selected' : '' ?>><?= esc($v['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Add or rename variants under <a href="<?= site_url('admin/route-variants') ?>">Route Variants</a>.</div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Create and add steps</button>
            <a class="btn btn-outline-secondary" href="<?= site_url('admin/routes') ?>">Cancel</a>
        </div>
    </form>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var sel = document.getElementById('type_choice'), box = document.getElementById('custom-type'), input = document.getElementById('type_name');
    if (!sel || !box || !input) { return; }
    function sync() {
        var other = sel.value === 'other';
        box.hidden = !other;
        input.required = other;
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
<?= $this->endSection() ?>
