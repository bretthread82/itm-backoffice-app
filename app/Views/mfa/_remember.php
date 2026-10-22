<?php if (! empty($canRemember)): ?>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
        <label class="form-check-label small" for="remember">Do not ask again on this device for <?= (int) $rememberDays ?> day<?= (int) $rememberDays === 1 ? '' : 's' ?></label>
    </div>
<?php endif; ?>
