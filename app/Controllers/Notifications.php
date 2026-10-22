<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class Notifications extends BaseController
{
    private const PER_PAGE = 20;

    public function index(): string
    {
        $userId = (int) auth()->id();
        $unread = $this->request->getGet('filter') === 'unread';
        $page   = max(1, (int) $this->request->getGet('page'));

        $build = static function () use ($userId, $unread) {
            $b = db_connect()->table('notifications')->where('user_id', $userId);

            return $unread ? $b->where('read_at', null) : $b;
        };

        $total = $build()->countAllResults();
        $rows  = $build()->orderBy('id', 'DESC')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('notifications/index', [
            'title'  => 'Notifications',
            'rows'   => $rows,
            'unread' => $unread,
            'pager'  => service('pager')->makeLinks($page, self::PER_PAGE, $total, 'bootstrap'),
        ]);
    }

    /**
     * JSON for the top-bar bell: unread count plus the latest items.
     */
    public function feed(): ResponseInterface
    {
        $userId = (int) auth()->id();
        $items  = db_connect()->table('notifications')->select('id, title, body, read_at, created_at')
            ->where('user_id', $userId)->orderBy('id', 'DESC')->limit(6)->get()->getResultArray();

        foreach ($items as &$item) {
            $item['unread'] = $item['read_at'] === null;
            $item['open']   = site_url('notifications/' . $item['id'] . '/open');
            $item['ago']    = $this->ago($item['created_at']);
            unset($item['read_at']);
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON([
            'count' => service('notifier')->unreadCount($userId),
            'items' => $items,
        ]);
    }

    /**
     * Marks one notification read and follows its link. Scoped to the owner.
     */
    public function open(int $id): RedirectResponse
    {
        $row = service('notifier')->markRead($id, (int) auth()->id());

        if (! $row) {
            return redirect()->to('notifications');
        }

        $url = $row['url'] ?: 'notifications';

        // Only follow internal links.
        if (preg_match('#^(https?:)?//#i', $url)) {
            $url = 'notifications';
        }

        return redirect()->to($url);
    }

    public function readAll(): RedirectResponse
    {
        service('notifier')->markAllRead((int) auth()->id());

        return redirect()->back()->with('message', 'All notifications marked as read.');
    }

    private function ago(string $datetime): string
    {
        $diff = time() - strtotime($datetime);

        return match (true) {
            $diff < 60     => 'just now',
            $diff < 3600   => floor($diff / 60) . ' min ago',
            $diff < 86400  => floor($diff / 3600) . ' h ago',
            $diff < 604800 => floor($diff / 86400) . ' d ago',
            default        => date('j M Y', strtotime($datetime)),
        };
    }
}
