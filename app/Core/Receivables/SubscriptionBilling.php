<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use CodeIgniter\Database\BaseConnection;

/**
 * Raises the draft customer invoice for each subscription cycle that has come due, and tells people ahead of time.
 *
 * Billing: an active subscription whose next bill date has arrived (in its company's calendar) gets a draft through
 * ReceivablesEngine::createDraft, raised as its owner; the invoice number and PDF are added by a person before it goes
 * through the normal approval route. A cycle is claimed in ar_subscription_invoices (unique per subscription and date)
 * inside one transaction with the draft, so it can never be billed twice. Missed days are caught up, a few cycles at most.
 *
 * Reminders: people are told once per lead-time window (ReceivablesSettings.subscriptionLeadDays, default 14, 7 and 1
 * days before), claimed in ar_subscription_notices, so the command can run any number of times a day.
 */
final class SubscriptionBilling
{
    /** Cycles caught up in one run for a subscription that was missed for a long time. */
    private const MAX_CATCH_UP = 3;

    public function __construct(
        private ?BaseConnection $db = null,
        private ?ReceivablesEngine $engine = null,
        private ?SubscriptionNotifier $notifier = null,
        private ?OverdueReminder $recipients = null,
    ) {
        $this->db         ??= db_connect();
        $this->engine     ??= new ReceivablesEngine($this->db);
        $this->notifier   ??= new SubscriptionNotifier($this->db);
        $this->recipients ??= new OverdueReminder($this->db);
    }

    /**
     * @return array{due: int, billed: int, failed: int, ended: int}
     */
    public function bill(bool $dryRun = false): array
    {
        $stats = ['due' => 0, 'billed' => 0, 'failed' => 0, 'ended' => 0];

        $subs = $this->db->table('ar_subscriptions')->where('status', 'active')
            ->where('next_bill_date <=', date('Y-m-d', strtotime('+1 day')))->orderBy('id')->get()->getResultArray();

        foreach ($subs as $sub) {
            $today = service('timezone')->todayFor((int) $sub['company_id']);

            for ($n = 0; $n < self::MAX_CATCH_UP && (string) $sub['next_bill_date'] <= $today; $n++) {
                $stats['due']++;

                if ($sub['end_date'] !== null && (string) $sub['next_bill_date'] > (string) $sub['end_date']) {
                    $this->end($sub, $dryRun);
                    $stats['ended']++;
                    break;
                }
                if ($dryRun) {
                    $stats['billed']++;
                    $sub['next_bill_date'] = SubscriptionService::nextDate((string) $sub['next_bill_date'], (string) $sub['frequency'], (int) $sub['bill_day']);
                    continue;
                }

                $next = $this->billCycle($sub);
                if ($next === null) {
                    $stats['failed']++;
                    break;
                }
                $stats['billed']++;
                $sub['next_bill_date'] = $next;
            }

            // A subscription whose last cycle is billed ends with it.
            if (! $dryRun && $sub['end_date'] !== null && (string) $sub['next_bill_date'] > (string) $sub['end_date'] && $this->isActive((int) $sub['id'])) {
                $this->end($sub, false);
                $stats['ended']++;
            }
        }

        return $stats;
    }

    /**
     * Tells owners and collectors about cycles coming up.
     *
     * @return array{upcoming: int, notified: int, skipped: int, recipients: int}
     */
    public function remind(bool $dryRun = false): array
    {
        $leads = self::leadDays();
        $stats = ['upcoming' => 0, 'notified' => 0, 'skipped' => 0, 'recipients' => 0];
        if ($leads === []) {
            return $stats;
        }

        $horizon = date('Y-m-d', strtotime('+' . (max($leads) + 1) . ' days'));
        $subs    = $this->db->table('ar_subscriptions')->where('status', 'active')->where('next_bill_date <=', $horizon)->orderBy('id')->get()->getResultArray();

        foreach ($subs as $sub) {
            $today = service('timezone')->todayFor((int) $sub['company_id']);
            $days  = (int) round((strtotime((string) $sub['next_bill_date']) - strtotime($today)) / 86400);

            // Not billed yet, so at least a day away; the smallest window that still covers it.
            $window = array_values(array_filter($leads, static fn (int $l): bool => $l >= $days));
            if ($days < 1 || $window === []) {
                continue;
            }
            if ($sub['end_date'] !== null && (string) $sub['next_bill_date'] > (string) $sub['end_date']) {
                continue;
            }

            $stats['upcoming']++;
            $lead       = min($window);
            $recipients = $this->recipients->recipients(['submitted_by' => $sub['owner_id'], 'company_id' => $sub['company_id']]);

            if ($dryRun) {
                $stats['notified']++;
                $stats['recipients'] += count($recipients);
                continue;
            }
            if (! $this->claimNotice((int) $sub['id'], (string) $sub['next_bill_date'], $lead, count($recipients))) {
                $stats['skipped']++;
                continue;
            }

            $this->notifier->dispatch('ar.subscription_due', (int) $sub['id'], $recipients, ['bill_date' => $sub['next_bill_date'], 'days' => $days]);
            $stats['notified']++;
            $stats['recipients'] += count($recipients);
        }

        return $stats;
    }

    /** @return list<int> lead days from settings, largest first, positive only */
    public static function leadDays(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) setting('ReceivablesSettings.subscriptionLeadDays'))),
            static fn (int $d): bool => $d > 0,
        )));
        rsort($days);

        return $days;
    }

    /**
     * Raises the draft for the cycle and moves the subscription on, atomically.
     *
     * @param array<string, mixed> $sub
     *
     * @return string|null the new next bill date, or null when the draft could not be raised (the cycle is left to retry)
     */
    private function billCycle(array $sub): ?string
    {
        $date = (string) $sub['next_bill_date'];
        $next = SubscriptionService::nextDate($date, (string) $sub['frequency'], (int) $sub['bill_day']);

        $this->db->transBegin();

        $this->db->query('INSERT IGNORE INTO ar_subscription_invoices (subscription_id, bill_date, created_at) VALUES (?, ?, ?)', [(int) $sub['id'], $date, date('Y-m-d H:i:s')]);
        $claimed = $this->db->affectedRows() === 1;

        $invoiceId = null;
        $reason    = '';
        if ($claimed) {
            try {
                $invoiceId = $this->engine->createDraft((int) $sub['owner_id'], [
                    'company_id' => $sub['company_id'], 'invoice_type_id' => $sub['invoice_type_id'], 'customer_id' => $sub['customer_id'],
                    'customer' => $sub['customer'], 'amount' => $sub['amount'], 'currency' => $sub['currency'], 'invoice_date' => $date,
                    'due_date' => date('Y-m-d', strtotime($date . ' +' . (int) $sub['payment_terms_days'] . ' days')),
                ]);
            } catch (ReceivablesException $e) {
                $reason = $e->getMessage();
            }
        }

        if ($invoiceId === null) {
            $this->db->transRollback();

            if ($claimed) {
                $this->notifier->dispatch('ar.subscription_failed', (int) $sub['id'], [(int) $sub['owner_id']], ['bill_date' => $date, 'reason' => $reason]);
            }

            return null;
        }

        $this->db->table('ar_subscription_invoices')->where(['subscription_id' => (int) $sub['id'], 'bill_date' => $date])->update(['invoice_id' => $invoiceId]);
        $this->db->table('ar_subscriptions')->where('id', (int) $sub['id'])->update(['next_bill_date' => $next, 'updated_at' => date('Y-m-d H:i:s')]);
        if (! $this->db->transStatus()) {
            $this->db->transRollback();

            return null;
        }
        $this->db->transCommit();

        service('audit')->log('ar.subscription_billed', 'ar_subscription', (int) $sub['id'], null, ['bill_date' => $date, 'invoice_id' => $invoiceId]);
        $this->notifier->dispatch(
            'ar.subscription_billed',
            (int) $sub['id'],
            $this->recipients->recipients(['submitted_by' => $sub['owner_id'], 'company_id' => $sub['company_id']]),
            ['bill_date' => $date, 'invoice_id' => $invoiceId],
        );

        return $next;
    }

    /**
     * @param array<string, mixed> $sub
     */
    private function end(array $sub, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $this->db->table('ar_subscriptions')->where('id', (int) $sub['id'])->update(['status' => 'ended', 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->log('ar.subscription_ended', 'ar_subscription', (int) $sub['id'], null, ['reason' => 'end date reached']);
    }

    private function isActive(int $id): bool
    {
        return $this->db->table('ar_subscriptions')->where(['id' => $id, 'status' => 'active'])->countAllResults() === 1;
    }

    private function claimNotice(int $id, string $billDate, int $lead, int $recipients): bool
    {
        $this->db->query(
            'INSERT IGNORE INTO ar_subscription_notices (subscription_id, bill_date, lead_days, recipients, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $billDate, $lead, $recipients, date('Y-m-d H:i:s')],
        );

        return $this->db->affectedRows() === 1;
    }
}
