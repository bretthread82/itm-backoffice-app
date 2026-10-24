<?php

declare(strict_types=1);

namespace Tests\Customers;

use App\Core\CustomerImporter;
use RuntimeException;
use Tests\Support\Mfa\MfaTestCase;

/**
 * @internal
 */
final class CustomerImporterTest extends MfaTestCase
{
    /** @return list<array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cu');
        file_put_contents($path, $csv);
        try {
            return (new CustomerImporter())->parse($path);
        } finally {
            unlink($path);
        }
    }

    public function testParseHandlesBomAliasedHeadersAndBlankLines(): void
    {
        $rows = $this->parseCsv("\xEF\xBB\xBFCustomer Code,Customer Name,Terms\nC1,Acme,30\n\n,,\nC2,Beta,\n");

        $this->assertCount(2, $rows);
        $this->assertSame('C1', $rows[0]['code']);
        $this->assertSame('30', $rows[0]['payment_terms_days']);
        $this->assertSame('Beta', $rows[1]['name']);
    }

    public function testParseNeedsCodeAndNameColumns(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parseCsv("name\nAcme\n");
    }

    public function testValidateFlagsBadRows(): void
    {
        $this->db->table('customers')->insert(['code' => 'C1', 'name' => 'Acme', 'active' => 1]);

        $v = (new CustomerImporter())->validate($this->parseCsv(
            "code,name,email,payment_terms_days,active\nC1,Acme Renamed,,,\nC2,Acme Renamed,,,\nC3,Gamma,bad,,\nC4,Delta,,999,\n,NoCode,,,\nC5,Acme,,,\nC6,Eps,,,maybe\nC7,Zeta,z@example.test,45,N\n",
        ));

        $this->assertFalse($v['ok']);
        $this->assertSame(['update', 'error', 'error', 'error', 'error', 'error', 'error', 'create'], array_column($v['rows'], 'action'));
        $this->assertStringContainsString('Name repeats line 2', $v['rows'][1]['errors'][0]);
        $this->assertStringContainsString('Email is not valid', $v['rows'][2]['errors'][0]);
        $this->assertStringContainsString('Payment terms', $v['rows'][3]['errors'][0]);
        $this->assertStringContainsString('Code is required', $v['rows'][4]['errors'][0]);
        $this->assertStringContainsString('Another customer already has this name', $v['rows'][5]['errors'][0]);
        $this->assertStringContainsString('Active must be', $v['rows'][6]['errors'][0]);
    }

    public function testCommitCreatesUpdatesAndLeavesBlanksAndTheRestAlone(): void
    {
        $this->db->table('customers')->insert(['code' => 'C1', 'name' => 'Acme', 'email' => 'keep@example.test', 'active' => 1]);
        $this->db->table('customers')->insert(['code' => 'C9', 'name' => 'Untouched', 'active' => 1]);

        $r = (new CustomerImporter())->commit($this->parseCsv("code,name,email,phone,payment_terms_days,active\nc1,Acme Ltd,,555-1,45,N\nC2,Beta,b@example.test,,,\n"), 1);

        $this->assertSame(['created' => 1, 'updated' => 1], $r);
        $acme = $this->db->table('customers')->where('code', 'C1')->get()->getRowArray();
        $this->assertSame('Acme Ltd', $acme['name']);
        $this->assertSame('keep@example.test', $acme['email'], 'A blank cell keeps the existing value.');
        $this->assertSame('555-1', $acme['phone']);
        $this->assertSame(45, (int) $acme['payment_terms_days']);
        $this->assertSame(0, (int) $acme['active']);
        $this->assertSame(1, (int) $this->db->table('customers')->where('code', 'C2')->get()->getRow()->active);
        $this->assertSame('Untouched', $this->db->table('customers')->where('code', 'C9')->get()->getRow()->name);
    }

    public function testCommitRefusesAFileWithErrorsAndWritesNothing(): void
    {
        try {
            (new CustomerImporter())->commit($this->parseCsv("code,name\nC1,Good\nC2,\n"), 1);
            $this->fail('Expected an exception.');
        } catch (RuntimeException) {
            $this->assertSame(0, $this->db->table('customers')->countAllResults());
        }
    }
}
