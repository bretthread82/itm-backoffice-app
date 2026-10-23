<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\SharePoint\Connections;
use App\Services\SharePoint\SecretBox;
use App\Services\SharePoint\SharePointException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeSharePointClient;

/**
 * @internal
 */
final class SharePointScreensTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    private FakeSharePointClient $sp;

    protected function setUp(): void
    {
        parent::setUp();

        $filters                    = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);

        $this->sp = new FakeSharePointClient();
        Services::injectMock('sharepoint', $this->sp);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('sharepoint');
        parent::tearDown();
    }

    private function shieldUser(string $email, bool $admin): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $email, 'is_admin' => $admin ? 1 : 0, 'active' => 1]));

        return $users->findById($users->getInsertID());
    }

    private function connection(array $over = []): int
    {
        $this->db->table('sharepoint_connections')->insert($over + [
            'name' => 'Main', 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => SecretBox::seal('top-secret-value'),
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'list_name' => '', 'library_name' => 'Documents',
            'folder' => 'Invoices', 'enabled' => 0, 'is_default' => 0,
        ]);

        return (int) $this->db->insertID();
    }

    public function testNonAdminIsTurnedAway(): void
    {
        $user = $this->shieldUser('plain@example.test', false);

        foreach (['admin/sharepoint', 'admin/sharepoint/new', 'admin/sharepoint-sync'] as $uri) {
            $this->actingAs($user)->get($uri)->assertRedirectTo(site_url('/'));
        }
        $this->actingAs($user)->post('admin/sharepoint', ['name' => 'Sneaky'])->assertRedirectTo(site_url('/'));
        $this->assertSame(0, $this->db->table('sharepoint_connections')->countAllResults());
    }

    public function testAdminScreensOpenAndNeverShowTheSecret(): void
    {
        $admin = $this->shieldUser('boss@example.test', true);
        $id    = $this->connection();

        foreach (['admin/sharepoint', 'admin/sharepoint/new', 'admin/sharepoint/' . $id . '/edit', 'admin/sharepoint-sync'] as $uri) {
            $result = $this->actingAs($admin)->get($uri);
            $result->assertStatus(200);
            $this->assertStringNotContainsString('top-secret-value', $result->getBody());
        }
    }

    public function testCreateAsPlaceholderThenTestAndSwitchOn(): void
    {
        $admin = $this->shieldUser('boss@example.test', true);

        $this->actingAs($admin)->post('admin/sharepoint', [
            'name' => 'Main', 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'top-secret-value',
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'library_name' => 'Documents', 'enabled' => 1,
        ]);
        $row = $this->db->table('sharepoint_connections')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['enabled'], 'cannot be on before a test');
        $id = (int) $row['id'];

        $this->actingAs($admin)->post('admin/sharepoint/' . $id . '/test');
        $this->assertSame(1, count($this->sp->tested), 'the test reached the (fake) client');
        $this->assertNotNull($this->db->table('sharepoint_connections')->where('id', $id)->get()->getRowArray()['last_tested_at']);

        $this->actingAs($admin)->post('admin/sharepoint/' . $id, [
            'name' => 'Main', 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => '',
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'library_name' => 'Documents', 'enabled' => 1,
        ]);
        $this->assertSame(1, (int) $this->db->table('sharepoint_connections')->where('id', $id)->get()->getRowArray()['enabled']);
    }

    public function testFailedTestIsRecordedAndShownToTheAdmin(): void
    {
        $admin = $this->shieldUser('boss@example.test', true);
        $id    = $this->connection();
        $this->sp->testResult = new SharePointException('Access denied to the site');

        $this->actingAs($admin)->post('admin/sharepoint/' . $id . '/test');

        $row = $this->db->table('sharepoint_connections')->where('id', $id)->get()->getRowArray();
        $this->assertStringContainsString('Access denied', (string) $row['last_error']);
        $this->actingAs($admin)->get('admin/sharepoint/' . $id . '/edit')->assertSee('Access denied to the site');
    }

    public function testTestRefusedWhenIncompleteAndMakesNoCall(): void
    {
        $admin = $this->shieldUser('boss@example.test', true);
        $id    = $this->connection(['client_secret' => null]);

        $this->actingAs($admin)->post('admin/sharepoint/' . $id . '/test');

        $this->assertSame(0, count($this->sp->tested));
    }

    public function testDeleteRefusedWhileInUse(): void
    {
        $admin   = $this->shieldUser('boss@example.test', true);
        $id      = $this->connection();
        $company = $this->makeCompany('USER');
        $this->db->table('companies')->where('id', $company)->update(['sharepoint_connection_id' => $id]);

        $this->actingAs($admin)->post('admin/sharepoint/' . $id . '/delete');

        $this->assertNotNull((new Connections($this->db))->find($id));
    }

    public function testSyncQueueListsItemsAndRetryWaitsWithoutAConnection(): void
    {
        $admin = $this->shieldUser('boss@example.test', true);
        $co    = $this->makeCompany('ACME');
        $inv = $this->makeInvoice($co, $admin->id, 'processed');
        $this->db->table('invoices')->where('id', $inv)->update(['invoice_number' => 'INV-77', 'supplier' => 'Acme', 'sync_status' => 'not_configured']);
        $this->db->table('sync_queue')->insert(['invoice_id' => $inv, 'status' => 'not_configured', 'attempts' => 0]);

        $this->actingAs($admin)->get('admin/sharepoint-sync')->assertSee('INV-77');
        $this->actingAs($admin)->get('admin/sharepoint-sync?status=failed')->assertDontSee('INV-77');

        $this->actingAs($admin)->post('admin/sharepoint-sync/' . $inv . '/retry');
        $this->assertSame('not_configured', $this->db->table('sync_queue')->where('invoice_id', $inv)->get()->getRowArray()['status']);
        $this->assertSame(0, count($this->sp->stored), 'no SharePoint call without an enabled connection');
    }
}
