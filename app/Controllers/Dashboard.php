<?php

namespace App\Controllers;

class Dashboard extends BaseController
{
    public function index(): string
    {
        $db = db_connect();

        $stats = [];

        if (can('users.view')) {
            $stats['active_users'] = $db->table('staff_profiles')->where('status', 'active')->where('deleted_at', null)->countAllResults();
        }

        $recent = $db->table('notifications')->where('user_id', auth()->id())
            ->orderBy('created_at', 'DESC')->limit(5)->get()->getResultArray();

        return view('dashboard/index', ['stats' => $stats, 'recent' => $recent, 'title' => 'Dashboard']);
    }
}
