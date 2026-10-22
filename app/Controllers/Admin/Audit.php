<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class Audit extends BaseController
{
    private const PER_PAGE = 30;

    public function index(): string
    {
        $action = trim((string) $this->request->getGet('action'));
        $page   = max(1, (int) $this->request->getGet('page'));
        $db     = db_connect();

        $build = static function () use ($db, $action) {
            $b = $db->table('audit_log a')
                ->select("a.*, sp.display_name AS actor")
                ->join('staff_profiles sp', 'sp.user_id = a.actor_id', 'left');

            if ($action !== '') {
                $b->like('a.action', $action, 'after');
            }

            return $b;
        };

        $total = $build()->countAllResults();
        $rows  = $build()->orderBy('a.id', 'DESC')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('admin/audit/index', [
            'title'  => 'Audit log',
            'rows'   => $rows,
            'action' => $action,
            'pager'  => service('pager')->makeLinks($page, self::PER_PAGE, $total, 'bootstrap'),
        ]);
    }
}
