<?php

namespace App\Core;

use Config\Help;

/**
 * Serves the user manual and FAQ, filtered to what one user is allowed to know about. A topic,
 * section or FAQ with a permission is hidden unless the user holds it (any one of a list), and
 * PermissionService already fails permissions of switched-off modules.
 */
class HelpService
{
    /**
     * Topics the user may open, ordered, each with only its permitted sections and FAQs.
     * Section and FAQ ids are stable anchors.
     *
     * @return array<string, array<string, mixed>> keyed by topic slug
     */
    public function topics(int $userId): array
    {
        $out = [];

        foreach (config(Help::class)->topics as $slug => $topic) {
            if (! $this->allowed($userId, $topic['permission'] ?? null)) {
                continue;
            }

            $sections = [];
            foreach ($topic['sections'] ?? [] as $i => $section) {
                if ($this->allowed($userId, $section['permission'] ?? null)) {
                    $section['id'] = 's-' . $this->slug($section['heading']) . '-' . $i;
                    $sections[]    = $section;
                }
            }

            $faqs = [];
            foreach ($topic['faqs'] ?? [] as $i => $faq) {
                if ($this->allowed($userId, $faq['permission'] ?? null)) {
                    $faq['id'] = 'f-' . $slug . '-' . $i;
                    $faqs[]    = $faq;
                }
            }

            $out[$slug] = ['slug' => $slug, 'sections' => $sections, 'faqs' => $faqs] + $topic;
        }

        uasort($out, static fn (array $a, array $b): int => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));

        return $out;
    }

    /**
     * @return array<string, mixed>|null null when the topic does not exist or the user may not see it
     */
    public function topic(int $userId, string $slug): ?array
    {
        return $this->topics($userId)[$slug] ?? null;
    }

    /**
     * Every FAQ the user may see, grouped by topic. A search narrows to questions and answers that contain the text.
     *
     * @return list<array{slug: string, title: string, icon: string, faqs: list<array<string, mixed>>}>
     */
    public function faqGroups(int $userId, string $query = ''): array
    {
        $groups = [];

        foreach ($this->topics($userId) as $slug => $topic) {
            $faqs = array_values(array_filter(
                $topic['faqs'],
                fn (array $f): bool => $this->matches($query, [$f['q'], $f['a']]),
            ));

            if ($faqs !== []) {
                $groups[] = ['slug' => $slug, 'title' => $topic['title'], 'icon' => $topic['icon'], 'faqs' => $faqs];
            }
        }

        return $groups;
    }

    /**
     * Manual sections and FAQs matching the text, each with a link target.
     *
     * @return list<array{kind: string, topic: string, title: string, label: string, anchor: string}>
     */
    public function search(int $userId, string $query): array
    {
        $query = trim($query);
        $hits  = [];

        if ($query === '') {
            return $hits;
        }

        foreach ($this->topics($userId) as $slug => $topic) {
            foreach ($topic['sections'] as $section) {
                if ($this->matches($query, [$section['heading'], ...($section['body'] ?? []), ...($section['steps'] ?? [])])) {
                    $hits[] = ['kind' => 'Manual', 'topic' => $slug, 'title' => $topic['title'], 'label' => $section['heading'], 'anchor' => $section['id']];
                }
            }

            foreach ($topic['faqs'] as $faq) {
                if ($this->matches($query, [$faq['q'], $faq['a']])) {
                    $hits[] = ['kind' => 'FAQ', 'topic' => $slug, 'title' => $topic['title'], 'label' => $faq['q'], 'anchor' => $faq['id']];
                }
            }
        }

        return $hits;
    }

    /**
     * Names of the roles the user holds, for the "your access" note.
     *
     * @return list<string>
     */
    public function roleNames(int $userId): array
    {
        return array_column(
            db_connect()->table('user_roles ur')->select('r.name')->join('roles r', 'r.id = ur.role_id')
                ->where('ur.user_id', $userId)->orderBy('r.name')->get()->getResultArray(),
            'name',
        );
    }

    /**
     * @param string|list<string>|null $permission
     */
    private function allowed(int $userId, string|array|null $permission): bool
    {
        if ($permission === null || $permission === []) {
            return true;
        }

        return service('permissions')->canAny($userId, (array) $permission);
    }

    /**
     * @param list<string> $haystack
     */
    private function matches(string $query, array $haystack): bool
    {
        $query = trim($query);

        if ($query === '') {
            return true;
        }

        foreach ($haystack as $text) {
            if (mb_stripos($text, $query) !== false) {
                return true;
            }
        }

        return false;
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }
}
