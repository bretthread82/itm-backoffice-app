<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NotificationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The notification bell and its pages. Reading is GET (polling and open-and-mark-read links) so
 * the bell works without needing a fresh CSRF token on every poll; "mark all read" is a form POST.
 */
class Notifications extends BaseController
{
    private const PER_PAGE = 30;

    public function unread(): ResponseInterface
    {
        $uid = (int) auth()->id();
        $svc = new NotificationService();

        $items = array_map(static fn (array $n): array => [
            'id'    => (int) $n['id'],
            'title' => $n['title'],
            'body'  => $n['body'],
            'url'   => site_url('notifications/' . $n['id'] . '/open'),
            'read'  => $n['read_at'] !== null,
            'priority' => (int) ($n['priority'] ?? 0) === 1,
            'ago'   => time_ago($n['created_at']),
            'readUrl' => site_url('notifications/' . $n['id'] . '/read'),
        ], $svc->latest($uid, 8, true));

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['count' => $svc->unreadCount($uid), 'items' => $items]);
    }

    public function index(): string
    {
        $uid        = (int) auth()->id();
        $onlyUnread = (bool) $this->request->getGet('unread');
        $page       = max(1, (int) $this->request->getGet('page'));
        $db         = db_connect();

        $builder = $db->table('user_notifications')->where('user_id', $uid);
        if ($onlyUnread) {
            $builder->where('read_at', null);
        }
        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('id', 'DESC')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return view('notifications/index', [
            'title'      => 'Notifications',
            'items'      => $rows,
            'onlyUnread' => $onlyUnread,
            'page'       => $page,
            'pages'      => (int) max(1, ceil($total / self::PER_PAGE)),
            'unread'     => (new NotificationService())->unreadCount($uid),
        ]);
    }

    /**
     * Marks one notification read and goes to what it is about.
     */
    public function open(int $id): RedirectResponse
    {
        $uid = (int) auth()->id();
        $row = db_connect()->table('user_notifications')->where(['id' => $id, 'user_id' => $uid])->get()->getRowArray();
        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        (new NotificationService())->markRead($uid, $id);

        return redirect()->to($row['url'] ?: 'notifications');
    }

    /**
     * Marks one notification read without leaving the page (the bell's "Mark read" button).
     */
    public function markRead(int $id): ResponseInterface
    {
        $uid = (int) auth()->id();
        $row = db_connect()->table('user_notifications')->where(['id' => $id, 'user_id' => $uid])->get()->getRowArray();
        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $svc = new NotificationService();
        $svc->markRead($uid, $id);

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['count' => $svc->unreadCount($uid)]);
    }

    public function readAll(): RedirectResponse
    {
        (new NotificationService())->markAllRead((int) auth()->id());

        // Back to the page the bell was used on (the bell and the Notifications page both post here).
        $back = (string) $this->request->getServer('HTTP_REFERER');
        $to   = str_starts_with($back, site_url()) ? $back : site_url('notifications');

        return redirect()->to($to)->with('message', 'All notifications marked as read.');
    }
}
