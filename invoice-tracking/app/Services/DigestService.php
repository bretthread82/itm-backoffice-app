<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Builds and sends the daily "what needs your attention" summary. One per user per day: running it
 * twice does nothing the second time. Users with nothing outstanding get nothing.
 */
final class DigestService
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?ApprovalQueue $queue = null,
        private ?NotificationService $notifier = null,
    ) {
        $this->db       ??= db_connect();
        $this->queue    ??= new ApprovalQueue($this->db);
        $this->notifier ??= new NotificationService($this->db);
    }

    /**
     * @return array{users: int, sent: int}
     */
    public function run(): array
    {
        $users = $this->db->table('users')->select('id')->where('active', 1)->get()->getResultArray();
        $sent  = 0;

        foreach ($users as $u) {
            $userId = (int) $u['id'];
            if ($this->notifier->digestSentToday($userId)) {
                continue;
            }

            $sections = $this->sectionsFor($userId);
            if ($sections === []) {
                continue;
            }

            $count = array_sum(array_map(static fn (array $s): int => count($s['items']), $sections));
            $title = 'Daily summary: ' . $count . ' invoice' . ($count === 1 ? '' : 's') . ' need' . ($count === 1 ? 's' : '') . ' attention';

            if ($this->notifier->dispatchDigest($userId, $title, $sections)) {
                $sent++;
            }
        }

        return ['users' => count($users), 'sent' => $sent];
    }

    /**
     * @return list<array{heading: string, items: list<array{text: string, url: string}>}>
     */
    public function sectionsFor(int $userId): array
    {
        $sections = [];

        $waiting = $this->queue->pendingFor($userId);
        if ($waiting !== []) {
            $sections[] = ['heading' => 'Waiting for your approval', 'items' => array_map(fn (array $i): array => $this->line($i, true), $waiting)];
        }

        $mine = $this->queue->mine($userId);
        if ($mine['needs_action'] !== []) {
            $sections[] = ['heading' => 'Your invoices that need changes', 'items' => array_map(fn (array $i): array => $this->line($i, false), $mine['needs_action'])];
        }
        if ($mine['waiting'] !== []) {
            $sections[] = ['heading' => 'Your invoices still in approval', 'items' => array_map(fn (array $i): array => $this->line($i, false), $mine['waiting'])];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $i
     *
     * @return array{text: string, url: string}
     */
    private function line(array $i, bool $showWaiting): array
    {
        $no   = $i['invoice_number'] ?: '(no number)';
        $text = "#{$no} · {$i['company_code']} · " . ($i['supplier'] ?: 'no supplier');
        if ($i['amount'] !== null) {
            $text .= ' · ' . $i['currency'] . ' ' . number_format((float) $i['amount'], 2);
        }
        if ($i['step_label'] !== '') {
            $text .= ' · with ' . $i['step_label'];
        }
        $text .= ' · ' . $i['days_waiting'] . ' day' . ($i['days_waiting'] === 1 ? '' : 's') . ' waiting';
        if ($i['days_past_due'] > 0) {
            $text .= ' · ' . $i['days_past_due'] . ' days past due';
        }

        return ['text' => $text, 'url' => site_url('invoices/' . $i['id'])];
    }
}
