<?php

declare(strict_types=1);

namespace App\Core\Invoices;

/**
 * An invoice's actions and free notes in time order, plus who completed each step. Callers must
 * already have checked that the user may see the invoice.
 */
final class InvoiceTimeline
{
    /**
     * @return array{0: list<array<string, mixed>>, 1: array<int, array<string, mixed>>} events, and the approving action per step order
     */
    public function for(int $invoiceId): array
    {
        $db = db_connect();

        $actions = $db->query(
            'SELECT a.*, u.display_name AS actor, o.display_name AS on_behalf_name, n.body AS note_body, n.kind AS note_kind
             FROM inv_actions a
             JOIN inv_users u ON u.id = a.acted_by
             LEFT JOIN inv_users o ON o.id = a.on_behalf_of
             LEFT JOIN inv_notes n ON n.id = a.note_id
             WHERE a.invoice_id = ? ORDER BY a.id',
            [$invoiceId],
        )->getResultArray();

        $used  = array_filter(array_column($actions, 'note_id'));
        $notes = $db->query(
            'SELECT n.*, u.display_name AS actor FROM inv_notes n JOIN inv_users u ON u.id = n.user_id WHERE n.invoice_id = ? ORDER BY n.id',
            [$invoiceId],
        )->getResultArray();

        $events = [];
        $acted  = [];
        foreach ($actions as $a) {
            $events[] = ['at' => $a['acted_at'], 'sort' => (int) $a['id'] * 2, 'type' => 'action'] + $a;
            if ($a['action'] === 'approve' && $a['step_order'] !== null) {
                $acted[(int) $a['step_order']] = $a;
            }
        }
        foreach ($notes as $n) {
            if (! in_array($n['id'], $used, false)) {
                $events[] = ['at' => $n['created_at'], 'sort' => (int) $n['id'] * 2 + 1, 'type' => 'note', 'actor' => $n['actor'], 'note_body' => $n['body'], 'note_kind' => $n['kind']];
            }
        }

        usort($events, static fn (array $x, array $y): int => strcmp((string) $x['at'], (string) $y['at']) ?: $x['sort'] <=> $y['sort']);

        return [$events, $acted];
    }
}
