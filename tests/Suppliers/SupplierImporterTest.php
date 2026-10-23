<?php

declare(strict_types=1);

namespace Tests\Suppliers;

use App\Core\SupplierImporter;
use RuntimeException;
use Tests\Support\Invoices\InvoiceTestCase;

/**
 * @internal
 */
final class SupplierImporterTest extends InvoiceTestCase
{
    /** @return list<array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'su');
        file_put_contents($path, $csv);
        try {
            return (new SupplierImporter())->parse($path);
        } finally {
            unlink($path);
        }
    }

    public function testParseHandlesBomAliasedHeadersAndBlankLines(): void
    {
        $rows = $this->parseCsv("\xEF\xBB\xBFSupplier Code,Supplier Name,Active\nS1,Acme,Y\n\n,,\nS2,Beta,\n");

        $this->assertCount(2, $rows);
        $this->assertSame('S1', $rows[0]['code']);
        $this->assertSame('Beta', $rows[1]['name']);
    }

    public function testParseNeedsCodeAndNameColumns(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parseCsv("name\nAcme\n");
    }

    public function testValidateFlagsMissingAndRepeatedValues(): void
    {
        $this->db->table('suppliers')->insert(['code' => 'S1', 'name' => 'Acme', 'active' => 1]);

        $v = (new SupplierImporter())->validate($this->parseCsv(
            "code,name,active\nS1,Acme Renamed,\nS2,Acme Renamed,\nS3,Gamma,maybe\n,NoCode,\nS4,Acme,\nS5,Delta,N\n",
        ));

        $this->assertFalse($v['ok']);
        $actions = array_column($v['rows'], 'action');
        $this->assertSame(['update', 'error', 'error', 'error', 'error', 'create'], $actions);
        $this->assertStringContainsString('Name repeats line 2', $v['rows'][1]['errors'][0]);
        $this->assertStringContainsString('Active must be', $v['rows'][2]['errors'][0]);
        $this->assertStringContainsString('Code is required', $v['rows'][3]['errors'][0]);
        $this->assertStringContainsString('Another supplier already has this name', $v['rows'][4]['errors'][0]);
    }

    public function testCommitCreatesUpdatesAndLeavesTheRestAlone(): void
    {
        $this->db->table('suppliers')->insert(['code' => 'S1', 'name' => 'Acme', 'active' => 1]);
        $this->db->table('suppliers')->insert(['code' => 'S9', 'name' => 'Untouched', 'active' => 1]);

        $r = (new SupplierImporter())->commit($this->parseCsv("code,name,active\ns1,Acme Ltd,N\nS2,Beta,\n"), 1);

        $this->assertSame(['created' => 1, 'updated' => 1], $r);
        $acme = $this->db->table('suppliers')->where('code', 'S1')->get()->getRowArray();
        $this->assertSame('Acme Ltd', $acme['name']);
        $this->assertSame(0, (int) $acme['active']);
        $this->assertSame(1, (int) $this->db->table('suppliers')->where('code', 'S2')->get()->getRow()->active);
        $this->assertSame('Untouched', $this->db->table('suppliers')->where('code', 'S9')->get()->getRow()->name);
    }

    public function testCommitRefusesAFileWithErrorsAndWritesNothing(): void
    {
        try {
            (new SupplierImporter())->commit($this->parseCsv("code,name\nS1,Good\nS2,\n"), 1);
            $this->fail('Expected an exception.');
        } catch (RuntimeException) {
            $this->assertSame(0, $this->db->table('suppliers')->countAllResults());
        }
    }
}
