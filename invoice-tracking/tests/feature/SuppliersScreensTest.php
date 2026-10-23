<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class SuppliersScreensTest extends FeatureTestCase
{
    private User $manager;
    private User $sue;
    private int $company;
    private int $type;

    protected function setUp(): void
    {
        parent::setUp();

        $users = model(UserModel::class);
        $users->save(new User(['email' => 'mgr@example.test', 'password' => 'a-long-test-password-1', 'display_name' => 'Mgr', 'is_manager' => 1, 'active' => 1]));
        $this->manager = $users->findById($users->getInsertID());

        $this->sue     = $this->shieldUser('sue@example.test', 'Sue Submitter');
        $this->company = $this->makeCompany('ACME');
        $this->join((int) $this->sue->id, $this->company, true);
        $this->type = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => (int) $this->sue->id, 'role' => 'Clerk', 'label' => 'Sue Submitter'],
        ])['type'];
    }

    public function testManagerAddsRenamesAndSwitchesOffSuppliersButAUserCannot(): void
    {
        $this->actingAs($this->manager)->post('admin/suppliers', ['code' => 'A1', 'name' => 'Acme Supplies'])->assertRedirect();
        $id = (int) $this->db->table('suppliers')->where('name', 'Acme Supplies')->get()->getRow()->id;

        $this->actingAs($this->manager)->post('admin/suppliers', ['code' => 'a1', 'name' => 'Other Name'])->assertRedirect();
        $this->assertSame(1, $this->db->table('suppliers')->countAllResults(), 'a repeated code is refused');

        $this->actingAs($this->manager)->post('admin/suppliers/' . $id, ['code' => 'A2', 'name' => 'Acme Ltd'])->assertRedirect();
        $row = $this->db->table('suppliers')->where('id', $id)->get()->getRowArray();
        $this->assertSame('Acme Ltd', $row['name']);
        $this->assertSame('A2', $row['code']);
        $this->assertSame(0, (int) $row['active'], 'unticked Active switches it off');

        $res = $this->actingAs($this->manager)->get('admin/suppliers');
        $res->assertStatus(200);
        $res->assertSee('Acme Ltd');

        $this->actingAs($this->sue)->post('admin/suppliers', ['code' => 'X', 'name' => 'Sneaky'])->assertRedirectTo(site_url('/'));
        $this->assertSame(0, $this->db->table('suppliers')->where('name', 'Sneaky')->countAllResults());
    }

    public function testTheFormOffersOnlyActiveSuppliersAndRefusesAnyOther(): void
    {
        $this->db->table('suppliers')->insert(['code' => 'L1', 'name' => 'Listed Co', 'active' => 1]);
        $this->db->table('suppliers')->insert(['code' => 'R1', 'name' => 'Retired Co', 'active' => 0]);

        $res = $this->actingAs($this->sue)->get('invoices/new');
        $res->assertStatus(200);
        $res->assertSee('L1 · Listed Co');
        $res->assertDontSee('Retired Co');

        $post = [
            'company_id' => (string) $this->company, 'invoice_type_id' => (string) $this->type, 'invoice_number' => 'S-1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '10', 'currency' => 'BBD', 'action' => 'save',
        ];

        foreach (['Typed By Hand', 'Retired Co'] as $bad) {
            $this->actingAs($this->sue)->post('invoices', $post + ['supplier' => $bad])->assertRedirect();
            $this->assertStringContainsString('Choose a supplier from the list', (string) session()->getFlashdata('error'));
        }
        $this->assertSame(0, $this->db->table('invoices')->where('invoice_number', 'S-1')->countAllResults());

        $this->actingAs($this->sue)->post('invoices', $post + ['supplier' => 'Listed Co'])->assertRedirect();
        $this->assertSame('Listed Co', $this->db->table('invoices')->where('invoice_number', 'S-1')->get()->getRow()->supplier);
    }
}
