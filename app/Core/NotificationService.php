<?php

namespace App\Core;

use Config\Backoffice;

/**
 * Single entry point for notifying users, for the core and every module.
 * Respects each user's per-type preferences. Email is sent inline and is
 * skipped (logged) when no sender address is configured in Config\Email.
 */
class NotificationService
{
    /**
     * @param int|list<int>        $userIds
     * @param array<string, mixed> $data
     */
    public function send(int|array $userIds, string $type, string $title, ?string $body = null, ?string $url = null, array $data = [], bool $forceEmail = false): void
    {
        $db   = db_connect();
        $now  = date('Y-m-d H:i:s');

        foreach (array_unique(array_map('intval', (array) $userIds)) as $userId) {
            $pref = $this->preference($userId, $type);

            if ($pref['in_app']) {
                $db->table('notifications')->insert([
                    'user_id'    => $userId,
                    'type'       => $type,
                    'title'      => mb_substr($title, 0, 200),
                    'body'       => $body,
                    'url'        => $url,
                    'data'       => $data === [] ? null : json_encode($data),
                    'created_at' => $now,
                ]);
            }

            // $forceEmail is for urgent notices (e.g. a high-priority invoice) that must reach the inbox whatever the preference.
            if ($pref['email'] || $forceEmail) {
                $this->email($userId, $title, $body, $url);
            }
        }
    }

    /**
     * Notify every active user who holds the permission (Super Admins included).
     *
     * @param list<int> $except user ids to skip, e.g. the actor
     */
    public function sendToPermission(string $permission, string $type, string $title, ?string $body = null, ?string $url = null, array $except = []): void
    {
        $superRole = config(Backoffice::class)->superRole;

        $rows = db_connect()->query(
            'SELECT DISTINCT ur.user_id FROM user_roles ur '
            . 'JOIN roles r ON r.id = ur.role_id '
            . 'JOIN staff_profiles sp ON sp.user_id = ur.user_id AND sp.status = \'active\' AND sp.deleted_at IS NULL '
            . 'LEFT JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'LEFT JOIN permissions p ON p.id = rp.permission_id '
            . 'WHERE r.key = ? OR p.key = ?',
            [$superRole, $permission],
        )->getResultArray();

        $ids = array_diff(array_map('intval', array_column($rows, 'user_id')), $except);

        if ($ids !== []) {
            $this->send(array_values($ids), $type, $title, $body, $url);
        }
    }

    public function unreadCount(int $userId): int
    {
        return db_connect()->table('notifications')->where('user_id', $userId)->where('read_at', null)->countAllResults();
    }

    public function markRead(int $id, int $userId): ?array
    {
        $db  = db_connect();
        $row = $db->table('notifications')->where('id', $id)->where('user_id', $userId)->get()->getRowArray();

        if ($row && $row['read_at'] === null) {
            $db->table('notifications')->where('id', $id)->update(['read_at' => date('Y-m-d H:i:s')]);
        }

        return $row;
    }

    public function markAllRead(int $userId): void
    {
        db_connect()->table('notifications')->where('user_id', $userId)->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @return array{in_app: bool, email: bool}
     */
    public function preference(int $userId, string $type): array
    {
        $def = config(Backoffice::class)->notificationTypes[$type] ?? ['in_app' => true, 'email' => false];
        $row = db_connect()->table('notification_preferences')->where('user_id', $userId)->where('type', $type)->get()->getRowArray();

        $inApp = $row ? (bool) $row['in_app'] : $def['in_app'];
        $email = $row ? (bool) $row['email'] : $def['email'];

        // Security-relevant notices always appear in-app.
        if (! empty($def['locked'])) {
            $inApp = true;
        }

        return ['in_app' => $inApp, 'email' => $email];
    }

    /**
     * @param array<string, array{in_app?: mixed, email?: mixed}> $prefs keyed by type
     */
    public function savePreferences(int $userId, array $prefs): void
    {
        $db    = db_connect();
        $types = config(Backoffice::class)->notificationTypes;

        foreach ($types as $type => $def) {
            $row = [
                'user_id' => $userId,
                'type'    => $type,
                'in_app'  => ! empty($def['locked']) ? 1 : (int) ! empty($prefs[$type]['in_app']),
                'email'   => (int) ! empty($prefs[$type]['email']),
            ];

            $exists = $db->table('notification_preferences')->where('user_id', $userId)->where('type', $type)->countAllResults();
            $exists
                ? $db->table('notification_preferences')->where('user_id', $userId)->where('type', $type)->update($row)
                : $db->table('notification_preferences')->insert($row);
        }
    }

    private function email(int $userId, string $title, ?string $body, ?string $url): void
    {
        $mailer = service('mailer');

        if (! $mailer->isConfigured()) {
            return;
        }

        $address = db_connect()->table('auth_identities')->select('secret')
            ->where('user_id', $userId)->where('type', 'email_password')->get()->getRow('secret');

        if ($address) {
            $mailer->send($address, $title, view('emails/notification', ['title' => $title, 'body' => $body, 'url' => $url ? site_url($url) : null]));
        }
    }
}
