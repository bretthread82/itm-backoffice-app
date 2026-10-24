<?php

declare(strict_types=1);

namespace Tests\Core;

use Tests\Support\Mfa\MfaTestCase;

/**
 * @internal
 */
final class NotificationReadTest extends MfaTestCase
{
    public function testMarkOneAndMarkAllAreScopedToTheOwner(): void
    {
        $me    = $this->makeUser('me@example.com');
        $other = $this->makeUser('other@example.com');
        $svc   = service('notifier');

        $base      = $svc->unreadCount($me);
        $otherBase = $svc->unreadCount($other);

        $svc->send($me, 'test', 'One');
        $svc->send($me, 'test', 'Two');
        $svc->send($other, 'test', 'Theirs');

        $this->assertSame($base + 2, $svc->unreadCount($me));

        $ids = array_column(db_connect()->table('notifications')->where('user_id', $me)->where('title', 'One')->get()->getResultArray(), 'id');
        $theirs = (int) db_connect()->table('notifications')->where('user_id', $other)->get()->getRow('id');

        $svc->markRead((int) $ids[0], $me);
        $this->assertSame($base + 1, $svc->unreadCount($me));

        $this->assertNull($svc->markRead($theirs, $me));
        $this->assertSame($otherBase + 1, $svc->unreadCount($other), 'Another user cannot mark my notification read.');

        $svc->markAllRead($me);
        $this->assertSame(0, $svc->unreadCount($me));
        $this->assertSame($otherBase + 1, $svc->unreadCount($other));
    }
}
