<?php

namespace Tests\Entra;

use Tests\Support\Entra\EntraTestCase;
use App\Core\Entra\EntraSyncService;

final class EntraSyncServiceTest extends EntraTestCase
{
    private function sync(array $users, array $groups = []): array
    {
        return (new EntraSyncService($this->fakeClient($users, $groups), $this->entra))->run();
    }

    private function roleId(string $key): int
    {
        return (int) db_connect()->table('roles')->where('key', $key)->get()->getRow('id');
    }

    public function testCreatesUsersDepartmentsAndManagers(): void
    {
        $stats = $this->sync([
            $this->entraUser('boss-1', 'boss@example.com', ['department' => 'Finance', 'jobTitle' => 'CFO']),
            $this->entraUser('emp-1', 'emp@example.com', ['department' => 'Finance', 'manager' => ['id' => 'boss-1'], 'employeeId' => 'E100', 'businessPhones' => ['555'], 'employeeHireDate' => '2024-03-01T00:00:00Z']),
        ]);

        $this->assertSame(2, $stats['created']);
        $this->assertSame([], $stats['errors']);

        $boss = $this->profileByOid('boss-1');
        $emp  = $this->profileByOid('emp-1');
        $this->assertSame((int) $boss['user_id'], (int) $emp['manager_id']);
        $this->assertSame($boss['department_id'], $emp['department_id']);
        $this->assertSame('E100', $emp['employee_no']);
        $this->assertSame('2024-03-01', $emp['start_date']);
        $this->assertSame('555', $emp['phone']);
        $this->seeInDatabase('entra_sync_runs', ['created' => 2]);
    }

    public function testIsIdempotent(): void
    {
        $users = [$this->entraUser('a-1', 'a@example.com'), $this->entraUser('b-1', 'b@example.com')];
        $this->sync($users);
        $second = $this->sync($users);

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, db_connect()->table('staff_profiles')->countAllResults());
    }

    public function testSkipsGuestsAndObjectsWithoutAnEmail(): void
    {
        $this->sync([
            $this->entraUser('m-1', 'member@example.com'),
            $this->entraUser('g-1', 'guest@example.com', ['userType' => 'Guest']),
            ['id' => 'room-1', 'mail' => null, 'userPrincipalName' => 'not-an-email', 'userType' => 'Member', 'accountEnabled' => true],
        ]);

        $this->assertNotNull($this->profileByOid('m-1'));
        $this->assertNull($this->profileByOid('g-1'));
        $this->assertNull($this->profileByOid('room-1'));
    }

    public function testEmptyGraphResponseAbortsWithoutDeactivatingAnyone(): void
    {
        $this->sync([$this->entraUser('a-1', 'a@example.com')]);

        $stats = $this->sync([]);

        $this->assertNotEmpty($stats['errors']);
        $this->assertSame(0, $stats['deactivated']);
        $this->assertSame('active', $this->profileByOid('a-1')['status']);
    }

    public function testDisabledOrRemovedUsersAreDeactivatedAndRestored(): void
    {
        $keep = [];

        for ($i = 1; $i <= 8; $i++) {
            $keep[] = $this->entraUser("u-{$i}", "u{$i}@example.com");
        }

        $this->entra->deactivateLimitPercent = 30;
        $this->sync($keep);

        // u-1 disabled in Entra, u-2 removed from Entra: 2 of 8 = 25% (under the limit).
        $next = $keep;
        $next[0]['accountEnabled'] = false;
        unset($next[1]);
        $stats = $this->sync(array_values($next));

        $this->assertSame(2, $stats['deactivated']);
        $this->assertSame('inactive', $this->profileByOid('u-1')['status']);
        $this->assertSame('inactive', $this->profileByOid('u-2')['status']);

        // Both come back.
        $stats = $this->sync($keep);
        $this->assertSame(2, $stats['reactivated']);
        $this->assertSame('active', $this->profileByOid('u-1')['status']);
    }

    public function testMassDeactivationIsBlockedBySafetyLimit(): void
    {
        $all = [];

        for ($i = 1; $i <= 8; $i++) {
            $all[] = $this->entraUser("u-{$i}", "u{$i}@example.com");
        }

        $this->sync($all);
        $stats = $this->sync(array_slice($all, 0, 2));   // 6 of 8 "vanish"

        $this->assertSame(0, $stats['deactivated']);
        $this->assertStringContainsString('safety limit', $stats['errors'][0]);
        $this->assertSame('active', $this->profileByOid('u-8')['status']);
    }

    public function testLocalAccountsAreNeverTouchedBySync(): void
    {
        $this->sync([$this->entraUser('a-1', 'a@example.com')]);
        $localId = (new \App\Core\UserService())->create(['email' => 'local@example.com', 'first_name' => 'L', 'last_name' => 'U'], [])['id'];

        $this->sync([$this->entraUser('a-1', 'a@example.com')]);

        $this->assertSame('active', db_connect()->table('staff_profiles')->where('user_id', $localId)->get()->getRow('status'));
    }

    public function testGroupMembershipGrantsAndRevokesOnlyMappedRoles(): void
    {
        $db = db_connect();
        $db->table('entra_group_role_map')->insert(['entra_group_id' => 'g-managers', 'role_id' => $this->roleId('manager')]);

        $users = [$this->entraUser('a-1', 'a@example.com'), $this->entraUser('b-1', 'b@example.com')];
        $this->sync($users, ['g-managers' => ['a-1']]);

        $a = (int) $this->profileByOid('a-1')['user_id'];
        $b = (int) $this->profileByOid('b-1')['user_id'];
        $this->assertSame(['manager', 'staff'], $this->roleKeys($a));
        $this->assertSame(['staff'], $this->roleKeys($b));

        // A hand-assigned, unmapped role survives; the mapped one is revoked when membership ends.
        $db->table('user_roles')->insert(['user_id' => $a, 'role_id' => $this->roleId('admin')]);
        $this->sync($users, ['g-managers' => []]);

        $this->assertSame(['admin', 'staff'], $this->roleKeys($a));
    }

    public function testSuperAdminCannotBeGrantedThroughGroupMapping(): void
    {
        $this->sync([$this->entraUser('a-1', 'a@example.com')]);
        db_connect()->table('entra_group_role_map')->insert(['entra_group_id' => 'g-evil', 'role_id' => $this->roleId('super_admin')]);

        $this->sync([$this->entraUser('a-1', 'a@example.com')], ['g-evil' => ['a-1']]);

        $this->assertNotContains('super_admin', $this->roleKeys((int) $this->profileByOid('a-1')['user_id']));
    }

    public function testUnreadableGroupLeavesRolesUntouched(): void
    {
        $db = db_connect();
        $db->table('entra_group_role_map')->insert(['entra_group_id' => 'g-gone', 'role_id' => $this->roleId('manager')]);
        $users = [$this->entraUser('a-1', 'a@example.com')];
        $this->sync($users, ['g-gone' => ['a-1']]);
        $a = (int) $this->profileByOid('a-1')['user_id'];

        $stats = $this->sync($users, []);   // group now errors (deleted)

        $this->assertStringContainsString('Group roles were not updated', implode(' ', $stats['errors']));
        $this->assertContains('manager', $this->roleKeys($a), 'A failed group read must not strip access.');
    }

    public function testRefusesToRunWhenEntraIsNotReady(): void
    {
        $this->entra->enabled = false;

        $this->expectException(\RuntimeException::class);
        $this->sync([$this->entraUser('a-1', 'a@example.com')]);
    }

    public function testConfigTreatsPlaceholdersAsUnconfigured(): void
    {
        $cfg               = new \Config\Entra();
        $cfg->enabled      = true;
        $cfg->tenantId     = '00000000-0000-0000-0000-000000000000';
        $cfg->clientId     = '00000000-0000-0000-0000-000000000000';
        $cfg->clientSecret = 'YOUR-CLIENT-SECRET';

        $this->assertFalse($cfg->isConfigured());
        $this->assertFalse($cfg->isReady());
        $this->assertTrue($this->entra->isReady());
    }
}
