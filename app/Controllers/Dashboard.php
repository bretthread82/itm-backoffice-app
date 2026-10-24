<?php

namespace App\Controllers;

use App\Core\Dashboard\DashboardService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The signed-in user's day: a Today tab plus a tab for each enabled module they can use.
 */
class Dashboard extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if ($this->request->getGet('tab') === 'invoices') { // module renamed to payables
            return redirect()->to('/?tab=payables', 301);
        }

        $userId = (int) auth()->id();
        $board  = new DashboardService();
        $tabs   = $board->tabsFor($userId);
        $active = $board->resolve($tabs, $this->request->getGet('tab'));
        $badges = $board->badges($userId, $tabs);

        $data = $active === DashboardService::TODAY
            ? ['unread' => $board->unreadNotifications($userId), 'unreadTotal' => service('notifier')->unreadCount($userId)]
            : $board->data($userId, $tabs[$active]);

        return view('dashboard/index', [
            'title'  => 'Dashboard',
            'tabs'   => $tabs,
            'active' => $active,
            'badges' => $badges,
            'tab'    => $data,
        ]);
    }
}
