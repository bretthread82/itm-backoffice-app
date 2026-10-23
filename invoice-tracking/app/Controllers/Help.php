<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HelpAudience;
use App\Services\NotificationService;

/**
 * The in-app manual and FAQ. Everyone signed in can open it, but each topic, paragraph, question and
 * alert type is only included when the user holds one of the capabilities it needs (see
 * HelpAudience). Filtering happens here, so content a user may not use is never sent to the browser.
 * The text lives in app/Views/help/content.php.
 */
class Help extends BaseController
{
    public function index(): string
    {
        $caps = (new HelpAudience())->for((int) auth()->id(), (bool) auth()->user()->is_admin, (bool) (auth()->user()->is_manager ?? false), (bool) (auth()->user()->is_workflow ?? false));

        /** @var list<array<string, mixed>> $all */
        $all    = require APPPATH . 'Views/help/content.php';
        $topics = [];

        foreach ($all as $topic) {
            if (! HelpAudience::allows($topic['needs'] ?? null, $caps)) {
                continue;
            }

            $topic['parts'] = [];
            if (trim((string) ($topic['body'] ?? '')) !== '') {
                $topic['parts'][] = (string) $topic['body'];
            }
            foreach ($topic['body_extra'] ?? [] as $part) {
                if (HelpAudience::allows($part['needs'] ?? null, $caps)) {
                    $topic['parts'][] = (string) $part['html'];
                }
            }

            $topic['faqs'] = array_values(array_filter(
                $topic['faqs'] ?? [],
                static fn (array $f): bool => HelpAudience::allows($f[2]['needs'] ?? null, $caps),
            ));

            $topic['events'] = ! empty($topic['events']) ? $this->eventsFor($caps) : [];
            unset($topic['body'], $topic['body_extra']);
            $topics[] = $topic;
        }

        return view('help/index', [
            'title'  => 'Help & Manual',
            'topics' => $topics,
            'abilities' => $this->abilities($caps),
        ]);
    }

    /**
     * Alert types that can reach this user.
     *
     * @param array<string, bool> $caps
     *
     * @return list<string>
     */
    private function eventsFor(array $caps): array
    {
        $out = [];
        foreach (NotificationService::EVENTS as $event => $label) {
            if (HelpAudience::allows(HelpAudience::EVENT_NEEDS[$event] ?? null, $caps)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * @param array<string, bool> $caps
     *
     * @return list<string>
     */
    private function abilities(array $caps): array
    {
        $map = [
            'submitter' => 'submit invoices',
            'approver'  => 'approve invoices',
            'group'     => 'act as a group-level user',
            'manager'   => 'manage users and approval routing',
            'admin'     => 'administer the system',
        ];

        return array_values(array_map(static fn (string $k): string => $map[$k], array_keys(array_filter($caps))));
    }
}
