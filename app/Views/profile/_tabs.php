<ul class="nav nav-tabs mb-4">
    <?php foreach (['profile' => ['Profile', 'profile'], 'security' => ['Password & security', 'profile/security'], 'notifications' => ['Notifications', 'profile/notifications']] + (service('mfaPolicy')->enabled() || service('mfa')->methods((int) auth()->id()) !== [] ? ['mfa' => ['Two-step verification', 'profile/mfa']] : []) as $key => [$label, $url]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= site_url($url) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
