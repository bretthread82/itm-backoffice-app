<?php

namespace Tests\Tenancy;

use Tests\Support\Mfa\MfaTestCase;

final class TenancyTest extends MfaTestCase
{
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->table('companies')->insert(['code' => 'ALPHA', 'name' => 'Alpha Ltd', 'active' => 1]);
        $this->a = (int) $db->insertID();
        $db->table('companies')->insert(['code' => 'BETA', 'name' => 'Beta Ltd', 'active' => 1]);
        $this->b = (int) $db->insertID();
    }

    public function testUserWithNoMembershipsSeesNothing(): void
    {
        $uid = $this->makeUser('none@example.com');

        $this->assertSame([], service('scope')->companyIdsFor($uid));
        $this->assertSame([], service('scope')->visibleCompanies($uid));
        $this->assertSame('none', service('activeCompany')->mode($uid));

        $rows = service('scope')->restrict(db_connect()->table('companies c'), $uid, 'c.id')->get()->getResultArray();
        $this->assertSame([], $rows);
    }

    public function testMembershipsLimitVisibleCompaniesAndCarryCapabilities(): void
    {
        $uid = $this->makeUser('member@example.com');
        service('memberships')->sync($uid, [$this->a => ['can_submit' => true, 'can_approve' => false, 'job_title' => 'Clerk']]);

        $scope = service('scope');
        $this->assertSame([$this->a], $scope->companyIdsFor($uid));
        $this->assertTrue($scope->canViewCompany($uid, $this->a));
        $this->assertFalse($scope->canViewCompany($uid, $this->b));
        $this->assertTrue($scope->canSubmit($uid, $this->a));
        $this->assertFalse($scope->canApprove($uid, $this->a));
        $this->assertSame('single', service('activeCompany')->mode($uid));
        $this->assertSame('Clerk', service('memberships')->forUser($uid)[$this->a]['job_title']);
    }

    public function testSyncAddsUpdatesAndRemoves(): void
    {
        $uid = $this->makeUser('sync@example.com');
        $m   = service('memberships');

        $m->sync($uid, [$this->a => ['can_submit' => true], $this->b => ['can_approve' => true]]);
        $this->assertCount(2, $m->forUser($uid));

        $m->sync($uid, [$this->b => ['can_submit' => true, 'can_approve' => false]]);
        $rows = $m->forUser($uid);
        $this->assertSame([$this->b], array_keys($rows));
        $this->assertSame('1', (string) $rows[$this->b]['can_submit']);
        $this->assertSame('0', (string) $rows[$this->b]['can_approve']);
    }

    public function testDeclaredCapabilitiesAreStoredCheckedAndRemoved(): void
    {
        $this->declareDemoCapability();
        $uid = $this->makeUser('cap@example.com');
        $m   = service('memberships');

        $m->sync($uid, [$this->a => ['capabilities' => ['demo.review', 'invoices.approve', 'bogus.thing']]]);

        $scope = service('scope');
        $this->assertTrue($scope->can($uid, $this->a, 'demo.review'));
        $this->assertTrue($scope->can($uid, $this->a, 'invoices.approve'));
        $this->assertTrue($scope->canApprove($uid, $this->a));
        $this->assertFalse($scope->can($uid, $this->a, 'invoices.submit'));
        $this->assertFalse($scope->can($uid, $this->b, 'demo.review'));
        $this->assertFalse($scope->can($uid, $this->a, 'bogus.thing'), 'Undeclared capabilities are never stored.');
        $this->assertEqualsCanonicalizing(['demo.review', 'invoices.approve'], $m->forUser($uid)[$this->a]['capabilities']);

        $m->sync($uid, [$this->a => ['capabilities' => []]]);
        $this->assertFalse($scope->can($uid, $this->a, 'demo.review'));
        $this->assertFalse($scope->can($uid, $this->a, 'invoices.approve'));

        $m->sync($uid, [$this->a => ['capabilities' => ['demo.review']]]);
        $m->sync($uid, []);
        $this->assertSame(0, db_connect()->table('user_company_capabilities')->where('user_id', $uid)->countAllResults(), 'Removing the membership removes its capabilities.');
    }

    public function testCapabilitiesOfSwitchedOffModulesAreHiddenAndKept(): void
    {
        $this->declareDemoCapability();
        $uid = $this->makeUser('off@example.com');
        $m   = service('memberships');
        $m->sync($uid, [$this->a => ['capabilities' => ['demo.review']]]);

        setting()->set('ModuleState.disabled', ['demo', 'invoices']);
        \Config\Services::resetSingle('modules');
        $this->assertSame([], array_keys($m->capabilities()));

        $m->sync($uid, [$this->a => ['capabilities' => []]]);

        setting()->set('ModuleState.disabled', []);
        \Config\Services::resetSingle('modules');
        $this->assertTrue(service('scope')->can($uid, $this->a, 'demo.review'), 'Saving while the module is off must not strip its rights.');
    }

    private function declareDemoCapability(): void
    {
        $config = config(\Config\Backoffice::class);
        $config->modules['demo'] = [
            'version' => '1.0.0', 'permissions' => [], 'menu' => [], 'optional' => true,
            'companyAccess' => ['review' => ['label' => 'Can review']],
        ];
    }

    public function testGroupLevelSeesEveryCompanyButGainsNoCapabilities(): void
    {
        $uid = $this->makeUser('group@example.com');
        service('memberships')->grantGroupLevel($uid, null);
        service('memberships')->grantGroupLevel($uid, null); // idempotent

        $scope = service('scope');
        $this->assertTrue($scope->isGroupLevel($uid));
        $this->assertNull($scope->companyIdsFor($uid));
        $this->assertCount(2, $scope->visibleCompanies($uid));
        $this->assertFalse($scope->canSubmit($uid, $this->a));
        $this->assertSame('multi', service('activeCompany')->mode($uid));

        service('memberships')->revokeGroupLevel($uid);
        $this->assertSame([], $scope->companyIdsFor($uid));
    }

    public function testActiveCompanyOnlyNarrows(): void
    {
        $uid = $this->makeUser('narrow@example.com');
        service('memberships')->sync($uid, [$this->a => [], $this->b => []]);
        $active = service('activeCompany');

        $this->assertTrue($active->set($uid, $this->a));
        $this->assertSame($this->a, $active->current($uid));

        $other = $this->makeUser('other@example.com');
        service('memberships')->sync($other, [$this->b => []]);
        $this->assertFalse($active->set($other, $this->a), 'A company the user cannot see is refused.');

        $this->assertTrue($active->set($uid, 0));
        $this->assertNull($active->current($uid));
    }

    public function testInactiveCompaniesAreNotOffered(): void
    {
        $uid = $this->makeUser('inactive@example.com');
        service('memberships')->sync($uid, [$this->a => [], $this->b => []]);
        db_connect()->table('companies')->where('id', $this->b)->update(['active' => 0]);

        $this->assertSame([$this->a], array_map(static fn (array $c): int => (int) $c['id'], service('scope')->visibleCompanies($uid)));
    }

    public function testDeletingAUserRemovesTheirMemberships(): void
    {
        $uid = $this->makeUser('gone@example.com');
        service('memberships')->sync($uid, [$this->a => []]);
        service('memberships')->grantGroupLevel($uid, null);

        db_connect()->table('users')->where('id', $uid)->delete();

        $this->assertSame(0, db_connect()->table('user_companies')->where('user_id', $uid)->countAllResults());
        $this->assertSame(0, db_connect()->table('group_level_users')->where('user_id', $uid)->countAllResults());
    }
}
