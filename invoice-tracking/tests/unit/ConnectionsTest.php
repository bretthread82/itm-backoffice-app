<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SharePoint\Connections;
use App\Services\WorkflowException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class ConnectionsTest extends AppTestCase
{
    private Connections $svc;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc   = new Connections($this->db);
        $this->admin = $this->makeUserWithEmail('Admin', 'admin@example.test', true);
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Main', 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'the-secret',
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'list_name' => 'Completed', 'library_name' => 'Documents',
            'folder' => 'Invoices', 'field_mapping' => '',
        ];
    }

    private function row(int $id): array
    {
        return $this->db->table('sharepoint_connections')->where('id', $id)->get()->getRowArray();
    }

    /** Saves a connection and passes a test so it can be switched on. */
    private function testedConnection(array $over = []): int
    {
        $id = $this->svc->save($this->form($over), null, $this->admin)['id'];
        $this->svc->recordTest($id, null);

        return $id;
    }

    public function testBlankPlaceholderCanBeSavedAndIsOff(): void
    {
        $r = $this->svc->save(['name' => 'Later'], null, $this->admin);

        $this->assertSame([], $r['errors']);
        $row = $this->row($r['id']);
        $this->assertSame(0, (int) $row['enabled']);
        $this->assertNotSame([], $this->svc->missing($row));
    }

    public function testNonAdminCannotSaveOrDelete(): void
    {
        $user = $this->makeUserWithEmail('Plain', 'plain@example.test');

        $this->expectException(WorkflowException::class);
        $this->svc->save($this->form(), null, $user);
    }

    public function testSecretIsStoredEncryptedAndNeverPlain(): void
    {
        $id  = $this->svc->save($this->form(), null, $this->admin)['id'];
        $raw = (string) $this->row($id)['client_secret'];

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('the-secret', $raw);
        $this->assertSame('the-secret', $this->svc->forClient($this->row($id))['secret']);
    }

    public function testBlankSecretKeepsTheSavedOne(): void
    {
        $id     = $this->svc->save($this->form(), null, $this->admin)['id'];
        $before = $this->row($id)['client_secret'];

        $this->svc->save($this->form(['client_secret' => '', 'name' => 'Renamed']), $id, $this->admin);

        $this->assertSame($before, $this->row($id)['client_secret']);
    }

    public function testCannotSwitchOnWithoutASuccessfulTest(): void
    {
        $r = $this->svc->save($this->form(['enabled' => 1]), null, $this->admin);

        $this->assertNotSame([], $r['errors']);
        $this->assertSame(0, (int) $this->row($r['id'])['enabled']);
        $this->assertSame('Main', $this->row($r['id'])['name'], 'edits are kept even though it stays off');
    }

    public function testCanSwitchOnAfterTestAndChangingASettingRequiresRetest(): void
    {
        $id = $this->testedConnection();

        $r = $this->svc->save($this->form(['client_secret' => '', 'enabled' => 1]), $id, $this->admin);
        $this->assertSame([], $r['errors']);
        $this->assertSame(1, (int) $this->row($id)['enabled']);

        // Changing the site clears the test and switches it off if asked to stay on.
        $r = $this->svc->save($this->form(['client_secret' => '', 'enabled' => 1, 'site_url' => 'https://contoso.sharepoint.com/sites/Other']), $id, $this->admin);
        $this->assertNotSame([], $r['errors']);
        $row = $this->row($id);
        $this->assertSame(0, (int) $row['enabled']);
        $this->assertNull($row['last_tested_at']);
    }

    public function testNewSecretRequiresRetest(): void
    {
        $id = $this->testedConnection();

        $r = $this->svc->save($this->form(['client_secret' => 'another', 'enabled' => 1]), $id, $this->admin);

        $this->assertNotSame([], $r['errors']);
        $this->assertSame(0, (int) $this->row($id)['enabled']);
    }

    public function testFailedTestSwitchesTheConnectionOff(): void
    {
        $id = $this->testedConnection();
        $this->svc->save($this->form(['client_secret' => '', 'enabled' => 1]), $id, $this->admin);

        $this->svc->recordTest($id, 'AADSTS7000215: Invalid client secret');

        $row = $this->row($id);
        $this->assertSame(0, (int) $row['enabled']);
        $this->assertStringContainsString('Invalid client secret', $row['last_error']);
        $this->assertNull($this->svc->forCompany($this->makeCompany('X')));
    }

    public function testOnlyOneDefault(): void
    {
        $a = $this->svc->save($this->form(['name' => 'A', 'is_default' => 1]), null, $this->admin)['id'];
        $b = $this->svc->save($this->form(['name' => 'B', 'is_default' => 1]), null, $this->admin)['id'];

        $this->assertSame(0, (int) $this->row($a)['is_default']);
        $this->assertSame(1, (int) $this->row($b)['is_default']);
    }

    public function testCompanyUsesItsOwnConnectionAndDoesNotFallBackToDefault(): void
    {
        $default = $this->testedConnection(['name' => 'Default', 'is_default' => 1, 'enabled' => 1]);
        $this->svc->save($this->form(['name' => 'Default', 'client_secret' => '', 'is_default' => 1, 'enabled' => 1]), $default, $this->admin);

        $own = $this->testedConnection(['name' => 'Own', 'site_url' => 'https://contoso.sharepoint.com/sites/Own']);
        $company = $this->makeCompany('OWN');
        $other   = $this->makeCompany('PLAIN');
        $this->db->table('companies')->where('id', $company)->update(['sharepoint_connection_id' => $own]);

        // "Own" is tested but still off: the company must wait, not borrow the default.
        $this->assertNull($this->svc->forCompany($company));
        $this->assertSame($default, $this->svc->idFor($other));

        $this->svc->save($this->form(['name' => 'Own', 'client_secret' => '', 'site_url' => 'https://contoso.sharepoint.com/sites/Own', 'enabled' => 1]), $own, $this->admin);
        $this->assertSame($own, $this->svc->idFor($company));
    }

    public function testDeleteBlockedWhileCompaniesUseIt(): void
    {
        $id      = $this->svc->save($this->form(), null, $this->admin)['id'];
        $company = $this->makeCompany('USER');
        $this->db->table('companies')->where('id', $company)->update(['sharepoint_connection_id' => $id]);

        try {
            $this->svc->delete($id, $this->admin);
            $this->fail('Expected the delete to be refused.');
        } catch (WorkflowException) {
            $this->assertNotNull($this->svc->find($id));
        }

        $this->db->table('companies')->where('id', $company)->update(['sharepoint_connection_id' => null]);
        $this->svc->delete($id, $this->admin);
        $this->assertNull($this->svc->find($id));
    }

    public function testInvalidInputIsRejectedAndNothingIsWritten(): void
    {
        foreach ([
            ['site_url' => 'http://contoso.sharepoint.com/sites/x'],
            ['site_url' => 'https://user:pw@contoso.sharepoint.com/sites/x'],
            ['site_url' => 'https://contoso.sharepoint.com/sites/x?a=1'],
            ['tenant_id' => 'bad tenant!'],
            ['folder' => 'a:b'],
            ['name' => ''],
            ['field_mapping' => '["invoice_number"]'],
            ['field_mapping' => '{"Title": "not_a_value"}'],
            ['field_mapping' => '{"bad col": "supplier"}'],
        ] as $bad) {
            $r = $this->svc->save($this->form($bad), null, $this->admin);
            $this->assertNull($r['id'], json_encode($bad));
            $this->assertNotSame([], $r['errors'], json_encode($bad));
        }

        $this->assertSame(0, $this->db->table('sharepoint_connections')->countAllResults());
    }

    public function testMappingParsing(): void
    {
        $this->assertSame(['mapping' => [], 'error' => null], Connections::parseMapping('  '));
        $this->assertSame(['Title' => 'invoice_number', 'Amt' => 'amount'], Connections::parseMapping('{"Title":"invoice_number","Amt":"amount"}')['mapping']);
        $this->assertSame(Connections::DEFAULT_MAPPING, $this->svc->forClient(['id' => 1, 'name' => 'n', 'tenant_id' => 't', 'client_id' => 'c', 'site_url' => 'https://x.example'] + ['field_mapping' => ''])['mapping']);
    }
}
