<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Counters shown beside sidebar items. Cached in the session for a minute because the approval
 * queue check is per-invoice. Counts are always limited to what the user may see.
 */
final class NavCounts
{
    private const TTL = 60;

    /** @return array{waiting: int, returned: int, overdue: int} */
    public static function for(int $userId): array
    {
        $cached = session()->get('nav_counts');
        if (is_array($cached) && ($cached['user'] ?? null) === $userId && (time() - (int) ($cached['at'] ?? 0)) < self::TTL) {
            return $cached['counts'];
        }

        $reports = new ReportService();
        $rows    = $reports->outstanding($userId);
        $counts  = [
            'waiting'  => count((new ApprovalQueue())->pendingFor($userId)),
            'returned' => count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'returned')),
            'overdue'  => count(array_filter($rows, static fn (array $r): bool => (int) $r['days_past_due'] > 0)),
        ];
        session()->set('nav_counts', ['user' => $userId, 'at' => time(), 'counts' => $counts]);

        return $counts;
    }
}
