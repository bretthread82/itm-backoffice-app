<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AccessScope;
use App\Services\AdminAccess;
use RuntimeException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class AccessScopeTest extends AppTestCase
{
    private AccessScope $scope;
    private AdminAccess $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new AccessScope($this->db);
        $this->admin = new AdminAccess($this->db);
    }

    public function testMemberSeesOnlyTheirCompanies(): void
    {
        $a = $this->makeCompany('AAA');
        $b = $this->makeCompany('BBB');
        $c = $this->makeCompany('CCC');
        $u = $this->makeUser('Multi Company');
        $this->join($u, $a);
        $this->join($u, $c);

        $this->assertEqualsCanonicalizing([$a, $c], $this->scope->companyIdsFor($u));
        $this->assertTrue($this->scope->canViewCompany($u, $a));
        $this->assertFalse($this->scope->canViewCompany($u, $b));
    }

    public function testUserWithNoCompaniesSeesNothing(): void
    {
        $a = $this->makeCompany('AAA');
        $u = $this->makeUser('Nobody');
        $inv = $this->makeInvoice($a, $this->makeUser('Submitter'));

        $this->assertSame([], $this->scope->companyIdsFor($u));
        $rows = $this->scope->restrict($this->db->table('invoices'), $u)->get()->getResultArray();
        $this->assertCount(0, $rows);
        $this->assertFalse($this->scope->canViewInvoice($u, $inv));
    }

    public function testRestrictFiltersInvoicesByMembership(): void
    {
        $a = $this->makeCompany('AAA');
        $b = $this->makeCompany('BBB');
        $sub = $this->makeUser('Submitter');
        $this->makeInvoice($a, $sub);
        $this->makeInvoice($b, $sub);
        $u = $this->makeUser('Only A');
        $this->join($u, $a);

        $rows = $this->scope->restrict($this->db->table('invoices'), $u)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($a, (int) $rows[0]['company_id']);
    }

    public function testGroupLevelUserSeesAllCompaniesIncludingLaterOnes(): void
    {
        $adminId = $this->makeUser('Admin', true);
        $gl = $this->makeUser('Group Level');
        $a = $this->makeCompany('AAA');
        $this->admin->grantGroupLevel($adminId, $gl);

        $this->assertNull($this->scope->companyIdsFor($gl));
        $this->assertTrue($this->scope->canViewCompany($gl, $a));

        $later = $this->makeCompany('ZZZ'); // company created after the grant
        $this->assertTrue($this->scope->canViewCompany($gl, $later));
        $this->makeInvoice($later, $adminId);
        $this->assertCount(1, $this->scope->restrict($this->db->table('invoices'), $gl)->get()->getResultArray());
    }

    public function testSubGroupsNeverAffectAccess(): void
    {
        $this->db->table('company_groups')->insert(['name' => 'Sub-group 1']);
        $g1 = (int) $this->db->insertID();
        $this->db->table('company_groups')->insert(['name' => 'Sub-group 2']);
        $g2 = (int) $this->db->insertID();
        $a = $this->makeCompany('AAA', $g1);
        $b = $this->makeCompany('BBB', $g1); // same sub-group as A
        $this->makeCompany('CCC', $g2);
        $u = $this->makeUser('Member of A');
        $this->join($u, $a);

        $this->assertFalse($this->scope->canViewCompany($u, $b));
    }

    public function testNonAdminCannotGrantOrRevokeGroupLevel(): void
    {
        $nonAdmin = $this->makeUser('Plain');
        $target = $this->makeUser('Target');

        $this->expectException(RuntimeException::class);
        $this->admin->grantGroupLevel($nonAdmin, $target);
    }

    public function testGroupLevelUserCannotGrantGroupLevel(): void
    {
        $adminId = $this->makeUser('Admin', true);
        $gl = $this->makeUser('Group Level');
        $other = $this->makeUser('Other');
        $this->admin->grantGroupLevel($adminId, $gl);

        $this->expectException(RuntimeException::class);
        $this->admin->grantGroupLevel($gl, $other);
    }

    public function testRevokeRemovesTheBoundaryAndIsAudited(): void
    {
        $adminId = $this->makeUser('Admin', true);
        $gl = $this->makeUser('Group Level');
        $this->admin->grantGroupLevel($adminId, $gl);
        $this->admin->revokeGroupLevel($adminId, $gl);

        $this->assertFalse($this->scope->isGroupLevel($gl));
        $events = array_column($this->db->table('audit_log')->select('event')->get()->getResultArray(), 'event');
        $this->assertContains('group_level.granted', $events);
        $this->assertContains('group_level.revoked', $events);
    }

    public function testLastActiveAdminCannotBeDemotedOrDisabled(): void
    {
        $only = $this->makeUser('Only Admin', true);

        try {
            $this->admin->setAdmin($only, $only, false);
            $this->fail('Demoting the last admin should throw.');
        } catch (RuntimeException) {
            $this->assertTrue($this->admin->isAdmin($only));
        }

        $this->expectException(RuntimeException::class);
        $this->admin->setActive($only, $only, false);
    }

    public function testAdminCanBeDemotedWhenAnotherAdminExists(): void
    {
        $one = $this->makeUser('Admin One', true);
        $two = $this->makeUser('Admin Two', true);

        $this->admin->setAdmin($one, $two, false);

        $this->assertFalse($this->admin->isAdmin($two));
        $this->assertTrue($this->admin->isAdmin($one));
    }
}
