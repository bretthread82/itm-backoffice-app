<?php
/**
 * Timezone picker. Pass: id/name (string), selected (?string), blank (?string label for the empty option).
 */
$selected = (string) ($selected ?? '');
$blank    = $blank ?? null;
?>
<select class="form-select" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>">
    <?php if ($blank !== null): ?>
        <option value=""><?= esc($blank) ?></option>
    <?php endif; ?>
    <?php foreach (service('timezone')->options() as $region => $zones): ?>
        <optgroup label="<?= esc($region, 'attr') ?>">
            <?php foreach ($zones as $zone): ?>
                <option value="<?= esc($zone, 'attr') ?>" <?= $selected === $zone ? 'selected' : '' ?>><?= esc($zone) ?></option>
            <?php endforeach; ?>
        </optgroup>
    <?php endforeach; ?>
</select>
