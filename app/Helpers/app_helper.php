<?php

use App\Core\PermissionService;
use Config\Backoffice;

if (! function_exists('permissions')) {
    function permissions(): PermissionService
    {
        return service('permissions');
    }
}

if (! function_exists('can')) {
    /**
     * Whether the signed-in user holds the permission.
     */
    function can(string $permission): bool
    {
        $id = auth()->id();

        return $id !== null && permissions()->can((int) $id, $permission);
    }
}

if (! function_exists('nav_items')) {
    /**
     * Sidebar entries the signed-in user may see, ordered.
     *
     * @return list<array<string, mixed>>
     */
    function nav_items(): array
    {
        $items = [];

        foreach (config(Backoffice::class)->modules as $module) {
            foreach ($module['menu'] as $item) {
                if ($item['permission'] === null || can($item['permission'])) {
                    $items[] = $item;
                }
            }
        }

        usort($items, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $items;
    }
}

if (! function_exists('nav_active')) {
    function nav_active(string $url): bool
    {
        $path = trim(uri_string(), '/');
        $url  = trim($url, '/');

        return $url === '' ? $path === '' : ($path === $url || str_starts_with($path, $url . '/'));
    }
}

if (! function_exists('current_profile')) {
    /**
     * Staff profile row for the signed-in user (memoised per request).
     *
     * @return array<string, mixed>|null
     */
    function current_profile(): ?array
    {
        static $profile = false;

        if ($profile === false) {
            $id      = auth()->id();
            $profile = $id === null
                ? null
                : db_connect()->table('staff_profiles')->where('user_id', $id)->get()->getRowArray();
        }

        return $profile;
    }
}

if (! function_exists('display_name')) {
    function display_name(): string
    {
        $profile = current_profile();

        if ($profile) {
            return $profile['display_name'] ?: trim($profile['first_name'] . ' ' . $profile['last_name']);
        }

        return auth()->user()?->email ?? 'User';
    }
}

if (! function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out   = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $out !== '' ? $out : '?';
    }
}

if (! function_exists('unread_notification_count')) {
    function unread_notification_count(): int
    {
        $id = auth()->id();

        return $id === null ? 0 : db_connect()->table('notifications')
            ->where('user_id', $id)->where('read_at', null)->countAllResults();
    }
}

if (! function_exists('avatar')) {
    /**
     * Avatar markup: the uploaded photo if there is one, otherwise initials.
     *
     * @param array<string, mixed> $person staff_profiles row (user_id, avatar_path, names)
     */
    function avatar(array $person, string $extraClass = ''): string
    {
        $name  = ($person['display_name'] ?? '') ?: trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $class = trim('avatar ' . $extraClass);

        if (! empty($person['avatar_path']) && ! empty($person['user_id'])) {
            $src = site_url('avatar/' . (int) $person['user_id']) . '?v=' . substr(md5((string) $person['avatar_path']), 0, 8);

            return '<img class="' . esc($class, 'attr') . ' avatar-img" src="' . esc($src, 'attr') . '" alt="' . esc($name, 'attr') . '">';
        }

        return '<span class="' . esc($class, 'attr') . '">' . esc(initials($name)) . '</span>';
    }
}
