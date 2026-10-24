<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use CodeIgniter\Database\BaseConnection;

/**
 * Tells people about overdue customer invoices: the person who raised it and everyone holding the per-company right
 * to record payments. An invoice is reminded the day after its due date and then every ReceivablesSettings.remindEveryDays
 * days while it stays unpaid. A day is claimed by inserting into ar_reminders first, so the command can be run any number
 * of times a day, or overlap itself, without anyone being told twice. "Today" is each invoice's own company calendar.
 */
final class OverdueReminder
{
    public function __construct(private ?BaseConnection $db = null, private ?ArNotifier $notifier = null, private ?Collections $collections = null)
    {
        $this->db          ??= db_connect();
        $this->notifier    ??= new ArNotifier($this->db);
        $this->collections ??= new Collections($this->db);
    }

    /**
     * @return array{overdue: int, reminded: int, skipped: int, recipients: int}
     */
    public function run(bool $dryRun = false): array
    {
        $every = max(1, (int) setting('ReceivablesSettings.remindEveryDays'));
        $stats = ['overdue' => 0, 'reminded' => 0, 'skipped' => 0, 'recipients' => 0];

        foreach ($this->collections->overdue() as $inv) {
            $stats['overdue']++;
            $today = service('timezone')->todayFor((int) $inv['company_id']);

            if (! $this->due((int) $inv['id'], $today, $every)) {
                $stats['skipped']++;
                continue;
            }

            $recipients = $this->recipients($inv);
            if ($dryRun) {
                $stats['reminded']++;
                $stats['recipients'] += count($recipients);
                continue;
            }

            if (! $this->claim((int) $inv['id'], $today, (int) $inv['days_overdue'], count($recipients))) {
                $stats['skipped']++; // another run took today
                continue;
            }

            $this->notifier->dispatch('ar.overdue', (int) $inv['id'], $recipients, [
                'days_overdue' => (int) $inv['days_overdue'],
                'outstanding'  => $inv['currency'] . ' ' . number_format((float) Collections::outstanding($inv), 2),
                'due_date'     => (string) $inv['due_date'],
            ]);
            $stats['reminded']++;
            $stats['recipients'] += count($recipients);
        }

        return $stats;
    }

    /** Never reminded, or the last reminder is at least $every days old. */
    private function due(int $invoiceId, string $today, int $every): bool
    {
        $last = $this->db->table('ar_reminders')->selectMax('sent_on', 'last')->where('invoice_id', $invoiceId)->get()->getRow()->last;

        return $last === null || (strtotime($today) - strtotime((string) $last)) / 86400 >= $every;
    }

    private function claim(int $invoiceId, string $today, int $days, int $recipients): bool
    {
        $this->db->query(
            'INSERT IGNORE INTO ar_reminders (invoice_id, sent_on, days_overdue, recipients, created_at) VALUES (?, ?, ?, ?, ?)',
            [$invoiceId, $today, $days, $recipients, date('Y-m-d H:i:s')],
        );

        return $this->db->affectedRows() === 1;
    }

    /**
     * The raiser and the company's collectors who are still active and can still see the company.
     * Also used for subscription notices, with the owner as submitted_by.
     *
     * @param array<string, mixed> $inv submitted_by, company_id
     *
     * @return list<int>
     */
    public function recipients(array $inv): array
    {
        $ids = [(int) $inv['submitted_by']];
        foreach ($this->db->table('user_company_capabilities')->select('user_id')->where(['company_id' => (int) $inv['company_id'], 'capability' => 'receivables.collect'])->get()->getResultArray() as $r) {
            $ids[] = (int) $r['user_id'];
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $active = array_column($this->db->table('inv_users')->select('id')->whereIn('id', $ids)->where('active', 1)->get()->getResultArray(), 'id');

        return array_values(array_map('intval', array_intersect($ids, array_map('intval', $active))));
    }
}
