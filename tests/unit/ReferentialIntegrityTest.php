<?php

namespace Tests\Unit;

use App\Core\UserService;
use App\Database\Seeds\RbacSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Deleting an organisational record must never delete people.
 */
final class ReferentialIntegrityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = RbacSeeder::class;

    private function person(string $email, array $extra = []): int
    {
        return (new UserService())->create(['email' => $email, 'first_name' => 'A', 'last_name' => 'B'] + $extra, [])['id'];
    }

    public function testDeletingADepartmentKeepsItsStaff(): void
    {
        $db = db_connect();
        $db->table('departments')->insert(['name' => 'Finance']);
        $deptId = (int) $db->insertID();
        $a      = $this->person('a@example.com', ['department_id' => $deptId]);

        $db->table('departments')->where('id', $deptId)->delete();

        $row = $db->table('staff_profiles')->where('user_id', $a)->get()->getRowArray();
        $this->assertNotNull($row, 'Deleting a department must not delete its staff.');
        $this->assertNull($row['department_id']);
    }

    public function testDeletingAManagerKeepsTheirReports(): void
    {
        $db      = db_connect();
        $manager = $this->person('boss@example.com');
        $report  = $this->person('report@example.com', ['manager_id' => $manager]);

        // Hard delete (the strictest case; the app soft-deletes).
        $db->table('users')->where('id', $manager)->delete();

        $row = $db->table('staff_profiles')->where('user_id', $report)->get()->getRowArray();
        $this->assertNotNull($row, 'Removing a manager must not delete their reports.');
        $this->assertNull($row['manager_id']);
    }
}
