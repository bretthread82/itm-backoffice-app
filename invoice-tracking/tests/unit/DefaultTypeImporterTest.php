<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DefaultTypeImporter;
use RuntimeException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class DefaultTypeImporterTest extends AppTestCase
{
    private function parseCsv(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dt');
        file_put_contents($path, $csv);
        try {
            return (new DefaultTypeImporter())->parse($path);
        } finally {
            unlink($path);
        }
    }

    public function testParseHandlesBomAndBlankLines(): void
    {
        $rows = $this->parseCsv("\xEF\xBB\xBFName,Company Codes,Active\nUtilities,AAA;BBB,Y\n\n,,\nFuel,,\n");

        $this->assertCount(2, $rows);
        $this->assertSame('Utilities', $rows[0]['name']);
        $this->assertSame('AAA;BBB', $rows[0]['company_codes']);
    }

    public function testParseRequiresNameColumn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parseCsv("title\nUtilities\n");
    }

    public function testValidateReportsErrors(): void
    {
        $this->makeCompany('AAA');
        $rows = $this->parseCsv("name,company_codes,active\nUtilities,NOPE,Y\nutilities,,\nFuel,AAA,N\nRent,,maybe\n");

        $preview = (new DefaultTypeImporter())->validate($rows);

        $this->assertFalse($preview['ok']);
        $this->assertSame(4, $preview['counts']['error']);
    }

    public function testCommitCreatesDefaultsAndAssignsIdempotently(): void
    {
        $a = $this->makeCompany('AAA');
        $b = $this->makeCompany('BBB');
        $this->db->table('invoice_types')->insert(['company_id' => $a, 'name' => 'utilities', 'active' => 1]);
        $rows = $this->parseCsv("name,company_codes,active\nUtilities,AAA;BBB,Y\nFuel,,\n");

        $importer = new DefaultTypeImporter();
        $result   = $importer->commit($rows, 1);

        $this->assertSame(['created' => 2, 'updated' => 0, 'copies_added' => 1, 'copies_existing' => 1], $result);
        $this->assertSame(2, $this->db->table('default_invoice_types')->countAllResults());
        $this->assertSame(1, $this->db->table('invoice_types')->where('company_id', $a)->countAllResults());
        $this->assertSame(1, $this->db->table('invoice_types')->where('company_id', $b)->countAllResults());

        $again = $importer->commit($rows, 1);
        $this->assertSame(['created' => 0, 'updated' => 2, 'copies_added' => 0, 'copies_existing' => 2], $again);
        $this->assertSame(2, $this->db->table('invoice_types')->countAllResults());
    }

    public function testCommitWithErrorsWritesNothing(): void
    {
        $rows = $this->parseCsv("name,company_codes,active\nUtilities,,\nFuel,NOPE,\n");

        try {
            (new DefaultTypeImporter())->commit($rows, 1);
            $this->fail('Expected an exception.');
        } catch (RuntimeException) {
            $this->assertSame(0, $this->db->table('default_invoice_types')->countAllResults());
        }
    }
}
