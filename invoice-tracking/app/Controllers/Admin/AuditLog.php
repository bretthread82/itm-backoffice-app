<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Read-only view of the audit log (administrators only, route filter). Nothing here can edit or delete entries.
 */
class AuditLog extends BaseController
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $db    = db_connect();
        $event = trim((string) $this->request->getGet('event'));
        $user  = (int) $this->request->getGet('user');
        $from  = $this->date((string) $this->request->getGet('from'));
        $to    = $this->date((string) $this->request->getGet('to'));
        $page  = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('audit_log a')->select('a.*, u.display_name, u.username')->join('users u', 'u.id = a.user_id', 'left');
        if ($event !== '') {
            $builder->like('a.event', $event, 'after');
        }
        if ($user > 0) {
            $builder->where('a.user_id', $user);
        }
        if ($from !== '') {
            $builder->where('a.created_at >=', $from . ' 00:00:00');
        }
        if ($to !== '') {
            $builder->where('a.created_at <=', $to . ' 23:59:59');
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('a.id', 'DESC')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('admin/audit/index', [
            'title'  => 'Audit log',
            'rows'   => $rows,
            'events' => array_column($db->query('SELECT DISTINCT event FROM audit_log ORDER BY event')->getResultArray(), 'event'),
            'users'  => $db->query('SELECT DISTINCT u.id, COALESCE(NULLIF(u.display_name, \'\'), u.username) AS name FROM audit_log a JOIN users u ON u.id = a.user_id ORDER BY name')->getResultArray(),
            'f'      => ['event' => $event, 'user' => $user, 'from' => $from, 'to' => $to],
            'page'   => $page,
            'pages'  => (int) max(1, ceil($total / self::PER_PAGE)),
            'total'  => $total,
        ]);
    }

    private function date(string $v): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
    }
}
