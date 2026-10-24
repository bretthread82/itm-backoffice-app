<?php

declare(strict_types=1);

namespace App\Core\Receivables;

/**
 * Counters shown beside Receivables sidebar items. Cached in the session for a minute because the approval queue check
 * is per-invoice. Counts are always limited to what the user may see.
 */
final class ArNavCounts
{
    private const TTL = 60;

    /** @return array{waiting: int, returned: int, overdue: int} */
    public static function for(int $userId): array
    {
        $cached = session()->get('ar_nav_counts');
        if (is_array($cached) && ($cached['user'] ?? null) === $userId && (time() - (int) ($cached['at'] ?? 0)) < self::TTL) {
            return $cached['counts'];
        }

        $queue  = new ArApprovalQueue();
        $counts = [
            'waiting'  => count($queue->pendingFor($userId)),
            'returned' => count($queue->mine($userId)['needs_action']),
            'overdue'  => count($queue->overdueFor($userId)),
        ];
        session()->set('ar_nav_counts', ['user' => $userId, 'at' => time(), 'counts' => $counts]);

        return $counts;
    }
}
