<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use App\Core\Tenancy\AccessScope;

use CodeIgniter\Database\BaseConnection;

/**
 * Recent human activity on invoices (workflow actions and stand-alone notes), always limited to what
 * the user may see. Read-only: the underlying tables are append-only.
 */
final class ActivityFeed
{
    private const VERBS = [
        'submit'   => 'Submitted',
        'resubmit' => 'Resubmitted',
        'approve'  => 'Approved',
        'claim'    => 'Accepted',
        'release'  => 'Released',
        'return'   => 'Returned',
        'return_previous' => 'Returned to previous approver',
        'reject'   => 'Rejected',
        'cancel'   => 'Cancelled',
        'attach'   => 'Attached a document to',
    ];

    public function __construct(private ?BaseConnection $db = null, private ?AccessScope $scope = null)
    {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
    }

    /**
     * @return list<array{actor: string, text: string, invoice_id: int, at: string, override: bool}>
     */
    public function recent(int $userId, int $companyId = 0, int $limit = 8): array
    {
        $actions = $this->db->table('inv_actions a')
            ->select('a.id, a.action, a.acted_as, a.acted_at AS at, u.display_name AS actor, i.id AS invoice_id, i.invoice_number')
            ->join('inv_invoices i', 'i.id = a.invoice_id')
            ->join('inv_users u', 'u.id = a.acted_by');
        $this->scope->restrict($actions, $userId, 'i.company_id');
        if ($companyId > 0) {
            $actions->where('i.company_id', $companyId);
        }
        $actions = $actions->orderBy('a.id', 'DESC')->limit($limit)->get()->getResultArray();

        $notes = $this->db->table('inv_notes n')
            ->select('n.id, n.created_at AS at, u.display_name AS actor, i.id AS invoice_id, i.invoice_number')
            ->join('inv_invoices i', 'i.id = n.invoice_id')
            ->join('inv_users u', 'u.id = n.user_id')
            ->where('n.kind', 'note')
            ->where('n.id NOT IN (SELECT note_id FROM inv_actions WHERE note_id IS NOT NULL)', null, false);
        $this->scope->restrict($notes, $userId, 'i.company_id');
        if ($companyId > 0) {
            $notes->where('i.company_id', $companyId);
        }
        $notes = $notes->orderBy('n.id', 'DESC')->limit($limit)->get()->getResultArray();

        $items = [];
        foreach ($actions as $a) {
            $items[] = [
                'actor'      => (string) $a['actor'],
                'text'       => (self::VERBS[$a['action']] ?? ucfirst((string) $a['action'])) . ' ' . ($a['invoice_number'] ?: '#' . $a['invoice_id']),
                'invoice_id' => (int) $a['invoice_id'],
                'at'         => (string) $a['at'],
                'override'   => $a['acted_as'] === 'group_level',
            ];
        }
        foreach ($notes as $n) {
            $items[] = [
                'actor'      => (string) $n['actor'],
                'text'       => 'Commented on ' . ($n['invoice_number'] ?: '#' . $n['invoice_id']),
                'invoice_id' => (int) $n['invoice_id'],
                'at'         => (string) $n['at'],
                'override'   => false,
            ];
        }
        usort($items, static fn (array $x, array $y): int => strcmp($y['at'], $x['at']));

        return array_slice($items, 0, $limit);
    }
}
