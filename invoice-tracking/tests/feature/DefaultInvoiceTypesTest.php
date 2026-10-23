<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class DefaultInvoiceTypesTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $filters                    = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);

        $users = model(UserModel::class);
        $users->save(new User(['email' => 'boss@example.test', 'password' => 'a-long-test-password-1', 'display_name' => 'Boss', 'is_admin' => 1, 'active' => 1]));
        $this->admin = $users->findById($users->getInsertID());
    }

    private function makeDefault(string $name): int
    {
        $this->db->table('default_invoice_types')->insert(['name' => $name, 'active' => 1]);

        return (int) $this->db->insertID();
    }

    public function testAdminCanOpenScreenAndNonAdminCannot(): void
    {
        $this->actingAs($this->admin)->get('admin/default-invoice-types')->assertStatus(200);

        $users = model(UserModel::class);
        $users->save(new User(['email' => 'plain@example.test', 'password' => 'a-long-test-password-1', 'display_name' => 'Plain', 'is_admin' => 0, 'active' => 1]));
        $plain = $users->findById($users->getInsertID());
        $this->actingAs($plain)->get('admin/default-invoice-types')->assertRedirectTo(site_url('/'));
    }

    private function plainUser(bool $manager = false): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => ($manager ? 'mgr' : 'plain') . '@example.test', 'password' => 'a-long-test-password-1', 'display_name' => 'U', 'is_admin' => 0, 'is_manager' => $manager ? 1 : 0, 'active' => 1]));

        return $users->findById($users->getInsertID());
    }

    public function testManagersAndAdminsSetHighPriorityAndItReachesCompanyCopies(): void
    {
        $co      = $this->makeCompany('AAA');
        $default = $this->makeDefault('Urgent');
        (new \App\Services\DefaultTypeAssigner($this->db))->assign($default, 'Urgent', $co);

        $this->actingAs($this->plainUser(true))->post('admin/default-invoice-types/' . $default, ['name' => 'Urgent', 'active' => '1', 'high_priority' => '1']);
        $this->assertSame(1, (int) $this->db->table('invoice_types')->where('default_type_id', $default)->get()->getRowArray()['high_priority']);

        $this->actingAs($this->admin)->post('admin/default-invoice-types/' . $default, ['name' => 'Urgent', 'active' => '1']);
        $this->assertSame(0, (int) $this->db->table('invoice_types')->where('default_type_id', $default)->get()->getRowArray()['high_priority']);
    }

    public function testAStandardUserCannotChangeHighPriority(): void
    {
        $default = $this->makeDefault('Urgent');

        $this->actingAs($this->plainUser())->post('admin/default-invoice-types/' . $default, ['name' => 'Urgent', 'active' => '1', 'high_priority' => '1'])
            ->assertRedirectTo(site_url('/'));

        $this->assertSame(0, (int) $this->db->table('default_invoice_types')->where('id', $default)->get()->getRowArray()['high_priority']);
    }

    public function testAnyTypeCanBeFlaggedByAManagerButNotAStandardUser(): void
    {
        $co     = $this->makeCompany('AAA');
        $this->makeRoute($co, [['type' => 'approve', 'role' => 'Boss']], 'Hand made');
        $typeId = (int) $this->db->table('invoice_types')->where('name', 'Hand made')->get()->getRowArray()['id'];
        $flag   = fn (int $id): int => (int) $this->db->table('invoice_types')->where('id', $id)->get()->getRowArray()['high_priority'];

        $this->actingAs($this->plainUser())->post('admin/invoice-types/' . $typeId . '/priority', ['high_priority' => '1'])->assertRedirectTo(site_url('/'));
        $this->assertSame(0, $flag($typeId));

        $this->actingAs($this->plainUser(true))->post('admin/invoice-types/' . $typeId . '/priority', ['high_priority' => '1']);
        $this->assertSame(1, $flag($typeId));
        $audit = $this->db->table('audit_log')->where('event', 'invoice_type.priority_changed')->get()->getRowArray();
        $this->assertSame('{"from":0,"to":1}', $audit['detail']);

        // A copy of a default can be flagged on its own too, and saving the default overwrites it.
        $default = $this->makeDefault('Urgent');
        (new \App\Services\DefaultTypeAssigner($this->db))->assign($default, 'Urgent', $co);
        $copy = (int) $this->db->table('invoice_types')->where('default_type_id', $default)->get()->getRowArray()['id'];
        $this->actingAs($this->admin)->post('admin/invoice-types/' . $copy . '/priority', ['high_priority' => '1']);
        $this->assertSame(1, $flag($copy));
        $this->actingAs($this->admin)->post('admin/default-invoice-types/' . $default, ['name' => 'Urgent', 'active' => '1']);
        $this->assertSame(0, $flag($copy));
    }

    public function testUnknownTypeIs404(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->actingAs($this->admin)->post('admin/invoice-types/999999/priority', ['high_priority' => '1']);
    }

    public function testScreenListsDefaultAndCustomTypesAndFilters(): void
    {
        $co      = $this->makeCompany('AAA');
        $default = $this->makeDefault('From default');
        (new \App\Services\DefaultTypeAssigner($this->db))->assign($default, 'From default', $co);
        $this->makeRoute($co, [['type' => 'approve', 'role' => 'Boss']], 'Custom one');

        $all = $this->actingAs($this->admin)->get('admin/default-invoice-types');
        $all->assertSee('From default');
        $all->assertSee('Custom one');

        $custom = $this->actingAs($this->admin)->get('admin/default-invoice-types?source=custom');
        $custom->assertSee('Custom one');
        $custom->assertDontSee('hp-' . (int) $this->db->table('invoice_types')->where('default_type_id', $default)->get()->getRowArray()['id']);
    }

    public function testUploadScreenAndTemplateAreAvailable(): void
    {
        $this->actingAs($this->admin)->get('admin/default-invoice-types/upload')->assertStatus(200);
        $this->actingAs($this->admin)->get('admin/default-invoice-types/upload/template')->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function testCreateRejectsDuplicateNames(): void
    {
        $this->actingAs($this->admin)->post('admin/default-invoice-types', ['name' => 'Utilities']);
        $this->actingAs($this->admin)->post('admin/default-invoice-types', ['name' => ' utilities ']);

        $this->assertSame(1, $this->db->table('default_invoice_types')->countAllResults());
    }

    public function testAssignCopiesTypesIntoCompaniesWithoutDuplicating(): void
    {
        $a      = $this->makeCompany('AAA');
        $b      = $this->makeCompany('BBB');
        $util   = $this->makeDefault('Utilities');
        $fuel   = $this->makeDefault('Fuel');
        $this->db->table('invoice_types')->insert(['company_id' => $a, 'name' => 'utilities', 'active' => 1]);

        $this->actingAs($this->admin)->post('admin/default-invoice-types/assign', [
            'type_ids' => [(string) $util, (string) $fuel], 'company_ids' => [(string) $a, (string) $b],
        ]);

        // A already had Utilities (kept, linked); Fuel for A and both for B are new.
        $this->assertSame(2, $this->db->table('invoice_types')->where('company_id', $a)->countAllResults());
        $this->assertSame(2, $this->db->table('invoice_types')->where('company_id', $b)->countAllResults());
        $kept = $this->db->table('invoice_types')->where('company_id', $a)->where('name', 'utilities')->get()->getRowArray();
        $this->assertSame($util, $kept['default_type_id']);

        // Re-running is a no-op.
        $this->actingAs($this->admin)->post('admin/default-invoice-types/assign', [
            'type_ids' => [(string) $util, (string) $fuel], 'company_ids' => [(string) $a, (string) $b],
        ]);
        $this->assertSame(4, $this->db->table('invoice_types')->countAllResults());
    }

    public function testDeleteKeepsCompanyCopies(): void
    {
        $a    = $this->makeCompany('AAA');
        $util = $this->makeDefault('Utilities');
        $this->actingAs($this->admin)->post('admin/default-invoice-types/assign', ['type_ids' => [(string) $util], 'company_ids' => [(string) $a]]);

        $this->actingAs($this->admin)->post('admin/default-invoice-types/' . $util . '/delete');

        $this->assertSame(0, $this->db->table('default_invoice_types')->countAllResults());
        $copy = $this->db->table('invoice_types')->where('company_id', $a)->get()->getRowArray();
        $this->assertSame('Utilities', $copy['name']);
        $this->assertNull($copy['default_type_id']);
    }

    public function testInactiveDefaultsAreNotAssigned(): void
    {
        $a    = $this->makeCompany('AAA');
        $util = $this->makeDefault('Utilities');
        $this->db->table('default_invoice_types')->where('id', $util)->update(['active' => 0]);

        $this->actingAs($this->admin)->post('admin/default-invoice-types/assign', ['type_ids' => [(string) $util], 'company_ids' => [(string) $a]]);

        $this->assertSame(0, $this->db->table('invoice_types')->countAllResults());
    }
}
