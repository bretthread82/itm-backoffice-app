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

if (! function_exists('nav_tree')) {
    /**
     * Sidebar entries the user may see, with modules that declare a 'group' folded under one collapsible heading.
     * Each result is either a plain menu item or ['group' => true, 'label', 'icon', 'order', 'key', 'items' => [...]].
     *
     * @return list<array<string, mixed>>
     */
    function nav_tree(): array
    {
        $config = config(Backoffice::class);
        $tree   = [];
        $named  = [];

        foreach ($config->modules as $key => $module) {
            $own = [];

            foreach ($module['menu'] as $item) {
                if ($item['permission'] !== null && ! can($item['permission'])) {
                    continue;
                }

                if (isset($item['group'], $config->navGroups[$item['group']])) {
                    $named[$item['group']][] = $item;
                } else {
                    $own[] = $item;
                }
            }

            if ($own === []) {
                continue;
            }

            usort($own, static fn ($a, $b) => $a['order'] <=> $b['order']);

            if (isset($module['group'])) {
                $tree[] = ['group' => true, 'key' => (string) $key, 'items' => $own] + $module['group'];
            } else {
                array_push($tree, ...$own);
            }
        }

        foreach ($named as $key => $items) {
            // Groups kept off the sidebar (Administration) live on their own page instead.
            if (($config->navGroups[$key]['sidebar'] ?? true) === false) {
                continue;
            }

            usort($items, static fn ($a, $b) => $a['order'] <=> $b['order']);
            $tree[] = ['group' => true, 'key' => (string) $key, 'items' => $items] + $config->navGroups[$key];
        }

        usort($tree, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $tree;
    }
}

if (! function_exists('admin_tree')) {
    /**
     * Administration options the user may use, by section, for the Admin dashboard. Sections with nothing visible are
     * left out; items without a known section go under "Other". Visibility is the same can() check as the sidebar,
     * so a switched-off module's options disappear too.
     *
     * @return array<string, array{label: string, icon: string, order: int, items: list<array<string, mixed>>}>
     */
    function admin_tree(): array
    {
        $config   = config(Backoffice::class);
        $sections = [];

        foreach ($config->modules as $module) {
            foreach ($module['menu'] as $item) {
                if (($item['group'] ?? null) !== 'admin' || ($item['permission'] !== null && ! can($item['permission']))) {
                    continue;
                }

                $key = isset($config->adminSections[$item['section'] ?? '']) ? $item['section'] : 'other';

                $sections[$key] ??= ($config->adminSections[$key] ?? ['label' => 'Other', 'icon' => 'three-dots', 'order' => 999]) + ['items' => []];
                $sections[$key]['items'][] = $item;
            }
        }

        foreach ($sections as &$section) {
            usort($section['items'], static fn ($a, $b) => $a['order'] <=> $b['order']);
        }

        unset($section);
        uasort($sections, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $sections;
    }
}

if (! function_exists('admin_entry_for')) {
    /**
     * The Administration option a page belongs to (so admin pages can link back to the Admin dashboard), or null.
     *
     * @return array<string, mixed>|null
     */
    function admin_entry_for(?string $path = null): ?array
    {
        foreach (admin_tree() as $section) {
            foreach ($section['items'] as $item) {
                if (nav_active($item['url'], null, $path, [])) {
                    return $item;
                }
            }
        }

        return null;
    }
}
if (! function_exists('nav_badge')) {
    /**
     * Count shown beside a sidebar entry (menu item key 'badge'). Modules register their counters here.
     */
    function nav_badge(?string $key): int
    {
        $uid = (int) auth()->id();

        return match ($key) {
            'payables.waiting' => $uid > 0 ? \App\Core\Payables\NavCounts::for($uid)['waiting'] : 0,
            'receivables.waiting' => $uid > 0 ? \App\Core\Receivables\ArNavCounts::for($uid)['waiting'] : 0,
            default => 0,
        };
    }
}

if (! function_exists('nav_active')) {
    /**
     * @param string                  $url   menu url, optionally with a query (payables?status=draft)
     * @param string|null             $unless GET param that makes a plain entry inactive when present
     * @param array<string, mixed>|null $get  request query, defaults to the current one
     */
    function nav_active(string $url, ?string $unless = null, ?string $currentPath = null, ?array $get = null): bool
    {
        $path = trim($currentPath ?? uri_string(), '/');
        $get ??= service('request')->getGet() ?? [];

        [$url, $query] = array_pad(explode('?', $url, 2), 2, '');
        $url           = trim($url, '/');

        $match = $url === '' ? $path === '' : ($path === $url || str_starts_with($path, $url . '/'));

        if (! $match) {
            return false;
        }

        if ($query !== '') {
            parse_str($query, $wanted);

            foreach ($wanted as $k => $v) {
                if (($get[$k] ?? null) !== $v) {
                    return false;
                }
            }
        }

        return $unless === null || ! isset($get[$unless]) || $get[$unless] === '';
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

if (! function_exists('local_time')) {
    /**
     * A stored UTC datetime shown in the signed-in user's timezone.
     */
    function local_time(?string $utc, string $format = 'j M, H:i'): string
    {
        return service('timezone')->toLocal($utc, null, $format);
    }
}

if (! function_exists('days_overdue')) {
    /**
     * Whole days a due date (a calendar date) has been past, counted on the company's own calendar.
     */
    function days_overdue(string $dueDate, int $companyId): int
    {
        $today = service('timezone')->todayFor($companyId);

        return max(0, (int) floor((strtotime($today) - strtotime($dueDate)) / 86400) - 1);
    }
}

if (! function_exists('tz_label')) {
    /**
     * The timezone the signed-in user's times are shown in.
     */
    function tz_label(): string
    {
        return service('timezone')->forUser(auth()->id() === null ? null : (int) auth()->id());
    }
}
