<?php

namespace Tests\Core;

use Tests\Support\Mfa\MfaTestCase;

final class TimezoneTest extends MfaTestCase
{
    private int $sydney;
    private int $plain;

    protected function setUp(): void
    {
        parent::setUp();
        service('timezone')->forgetCache();

        $db = db_connect();
        $db->table('companies')->insert(['code' => 'SYD', 'name' => 'Sydney Pty', 'timezone' => 'Australia/Sydney', 'active' => 1]);
        $this->sydney = (int) $db->insertID();
        $db->table('companies')->insert(['code' => 'NOTZ', 'name' => 'No Zone Ltd', 'active' => 1]);
        $this->plain = (int) $db->insertID();
    }

    public function testUserOwnTimezoneBeatsCompany(): void
    {
        $uid = $this->makeUser('own@example.com');
        service('memberships')->sync($uid, [$this->sydney => ['can_submit' => true]]);
        db_connect()->table('staff_profiles')->where('user_id', $uid)->update(['timezone' => 'America/New_York']);

        $this->assertSame('America/New_York', service('timezone', false)->forUser($uid));
    }

    public function testFallsBackToFirstCompanyWithAZoneThenUtc(): void
    {
        $uid = $this->makeUser('co@example.com');
        service('memberships')->sync($uid, [$this->plain => ['can_submit' => true], $this->sydney => ['can_submit' => true]]);
        $this->assertSame('Australia/Sydney', service('timezone', false)->forUser($uid));

        $none = $this->makeUser('none@example.com');
        $this->assertSame('UTC', service('timezone', false)->forUser($none));
    }

    public function testConvertsBothWaysAcrossDst(): void
    {
        $tz = service('timezone', false);

        $this->assertSame('2026-07-01 12:00', $tz->toLocal('2026-07-01 11:00:00', 'Europe/London', 'Y-m-d H:i'));
        $this->assertSame('2026-01-01 11:00', $tz->toLocal('2026-01-01 11:00:00', 'Europe/London', 'Y-m-d H:i'));
        $this->assertSame('2026-07-01 11:00:00', $tz->toUtc('2026-07-01T12:00', 'Europe/London'));
    }

    public function testRejectsUnknownIdentifiers(): void
    {
        $tz = service('timezone', false);

        $this->assertTrue($tz->isValid('Europe/London'));
        $this->assertFalse($tz->isValid('Mars/Olympus'));
        $this->assertFalse($tz->isValid(''));
    }

    public function testDaysOverdueUsesTheCompanyCalendar(): void
    {
        $due = service('timezone', false)->todayFor($this->sydney);
        $this->assertSame(0, days_overdue($due, $this->sydney));
        $this->assertSame(0, days_overdue(date('Y-m-d', strtotime($due . ' -1 day')), $this->sydney));
        $this->assertSame(2, days_overdue(date('Y-m-d', strtotime($due . ' -3 days')), $this->sydney));
    }
}
