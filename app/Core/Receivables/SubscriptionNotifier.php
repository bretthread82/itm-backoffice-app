<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Subscription events as platform notifications (bell and email, by each person's preferences). Wording lives here;
 * delivery is NotificationService. Links go to the subscription, or to the draft invoice once one exists.
 */
final class SubscriptionNotifier
{
    private ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param list<int>            $userIds
     * @param array<string, mixed> $ctx     bill_date, days, invoice_id, reason
     */
    public function dispatch(string $event, int $subscriptionId, array $userIds, array $ctx = []): void
    {
        $sub = $this->db->table('ar_subscriptions s')
            ->select('s.id, s.customer, s.description, s.amount, s.currency, c.code AS company_code')
            ->join('companies c', 'c.id = s.company_id')
            ->where('s.id', $subscriptionId)->get()->getRowArray();
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));

        if ($sub === null || $userIds === []) {
            return;
        }

        [$title, $body] = self::describe($event, $sub, $ctx);
        $url            = ! empty($ctx['invoice_id']) ? 'receivables/' . (int) $ctx['invoice_id'] : 'receivables/subscriptions/' . $subscriptionId;

        service('notifier')->send($userIds, $event, $title, $body, $url, ['subscription_id' => $subscriptionId]);
    }

    /**
     * @param array<string, mixed> $sub
     * @param array<string, mixed> $ctx
     *
     * @return array{0: string, 1: string} title, body (plain text)
     */
    public static function describe(string $event, array $sub, array $ctx): array
    {
        $what   = "{$sub['customer']} ({$sub['company_code']}): {$sub['description']}";
        $amount = $sub['currency'] . ' ' . number_format((float) $sub['amount'], 2);
        $date   = (string) ($ctx['bill_date'] ?? '');
        $days   = (int) ($ctx['days'] ?? 0);

        return match ($event) {
            'ar.subscription_due' => [
                "Subscription billing is due " . ($days <= 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days")) . ": {$what}",
                "The next invoice, {$amount}, is billed on {$date}. A draft customer invoice will be created for you to finish and submit.",
            ],
            'ar.subscription_billed' => [
                "A draft customer invoice was created for {$what}",
                "Billing date {$date}, {$amount}. Add the invoice number and PDF, then submit it for approval.",
            ],
            'ar.subscription_failed' => [
                "A subscription could not be billed: {$what}",
                'The draft invoice for ' . $date . ' was not created: ' . (string) ($ctx['reason'] ?? 'unknown reason') . ' It will be tried again on the next run.',
            ],
            default => [(string) ($ctx['title'] ?? $event), (string) ($ctx['body'] ?? '')],
        };
    }
}
