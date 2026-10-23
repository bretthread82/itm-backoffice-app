<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class RouteVariantsScreensTest extends FeatureTestCase
{
    private \CodeIgniter\Shield\Entities\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->shieldUser('boss@example.test', 'The Admin', true);
    }

    private function variantId(string $key): int
    {
        return (int) $this->db->table('route_variants')->where('variant_key', $key)->get()->getRowArray()['id'];
    }

    public function testNonAdminCannotOpenVariants(): void
    {
        $this->actingAs($this->shieldUser('plain@example.test', 'Plain'))->get('admin/route-variants')->assertRedirectTo(site_url('/'));
    }

    public function testListShowsSeededVariants(): void
    {
        $result = $this->actingAs($this->admin)->get('admin/route-variants');
        $result->assertStatus(200);
        $result->assertSee('Standard');
        $result->assertSee('High Value');
    }

    public function testCanAddAndRenameAVariant(): void
    {
        $this->actingAs($this->admin)->post('admin/route-variants', ['name' => 'Big Spend', 'sort_order' => '30'])->assertRedirect();
        $row = $this->db->table('route_variants')->where('variant_key', 'big_spend')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('Big Spend', $row['name']);

        $this->actingAs($this->admin)->post('admin/route-variants/' . $row['id'], ['name' => 'Large Spend', 'sort_order' => '30', 'active' => '1'])->assertRedirect();
        $this->assertSame('Large Spend', $this->db->table('route_variants')->where('id', $row['id'])->get()->getRowArray()['name']);

        // The key never changes, so existing routes keep pointing at it.
        $this->assertSame('big_spend', $this->db->table('route_variants')->where('id', $row['id'])->get()->getRowArray()['variant_key']);
    }

    public function testDuplicateKeyOrNameIsRejected(): void
    {
        $this->actingAs($this->admin)->post('admin/route-variants', ['name' => 'Another', 'variant_key' => 'extra_approval'])->assertRedirect();
        $this->assertSame(0, $this->db->table('route_variants')->where('name', 'Another')->countAllResults());

        $this->actingAs($this->admin)->post('admin/route-variants', ['name' => 'high value'])->assertRedirect();
        $this->assertSame(2, $this->db->table('route_variants')->countAllResults());

        $this->actingAs($this->admin)->post('admin/route-variants', ['name' => 'Default'])->assertRedirect(); // key "default" is reserved
        $this->assertSame(2, $this->db->table('route_variants')->countAllResults());
    }

    public function testStandardCannotBeDeletedOrSwitchedOff(): void
    {
        $id = $this->variantId('default');

        $this->actingAs($this->admin)->post('admin/route-variants/' . $id . '/delete')->assertRedirect();
        $this->assertSame(1, $this->db->table('route_variants')->where('variant_key', 'default')->countAllResults());

        $this->actingAs($this->admin)->post('admin/route-variants/' . $id, ['name' => 'Normal'])->assertRedirect(); // no "active" posted
        $row = $this->db->table('route_variants')->where('id', $id)->get()->getRowArray();
        $this->assertSame('Normal', $row['name']);
        $this->assertSame(1, (int) $row['active']);
    }

    public function testVariantInUseCannotBeDeleted(): void
    {
        $company = $this->makeCompany('ACME');
        $this->makeRoute($company, [], 'Utilities', 'extra_approval', false, 'manual_flag');

        $this->actingAs($this->admin)->post('admin/route-variants/' . $this->variantId('extra_approval') . '/delete')->assertRedirect();
        $this->assertSame(1, $this->db->table('route_variants')->where('variant_key', 'extra_approval')->countAllResults());
    }

    public function testUnusedVariantCanBeDeleted(): void
    {
        $this->actingAs($this->admin)->post('admin/route-variants/' . $this->variantId('extra_approval') . '/delete')->assertRedirect();
        $this->assertSame(0, $this->db->table('route_variants')->where('variant_key', 'extra_approval')->countAllResults());
    }

    public function testNewRouteFormOffersVariantNamesAndAcceptsCustomOnes(): void
    {
        $company = $this->makeCompany('ACME');
        $this->actingAs($this->admin)->post('admin/route-variants', ['name' => 'Big Spend'])->assertRedirect();

        $this->actingAs($this->admin)->get('admin/routes/new')->assertSee('Big Spend');

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_name' => 'Fuel', 'variant' => 'big_spend'])->assertRedirect();
        $this->assertSame(1, $this->db->table('routes')->where('variant', 'big_spend')->countAllResults());

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_name' => 'Fuel', 'variant' => 'nope'])->assertRedirect();
        $this->assertSame(0, $this->db->table('routes')->where('variant', 'nope')->countAllResults());
    }
}
