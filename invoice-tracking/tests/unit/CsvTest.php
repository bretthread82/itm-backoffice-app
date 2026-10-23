<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Csv;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CsvTest extends CIUnitTestCase
{
    public function testStartsWithByteOrderMarkAndHeaderRow(): void
    {
        $csv = Csv::build(['A', 'B'], [['x', 'y']]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("A,B\n", $csv);
        $this->assertStringContainsString("x,y\n", $csv);
    }

    public function testCellsThatLookLikeFormulasAreNeutralised(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tTAB", "\rCR", '=HYPERLINK("http://evil","x")'] as $evil) {
            $this->assertSame("'" . $evil, Csv::safe($evil), $evil);
        }
    }

    public function testOrdinaryTextAndNumbersAreUntouched(): void
    {
        $this->assertSame('Acme Supplies', Csv::safe('Acme Supplies'));
        $this->assertSame('', Csv::safe(null));
        $this->assertSame('', Csv::safe(''));
        $this->assertSame(1250.5, Csv::safe(1250.5));
        $this->assertSame(-3, Csv::safe(-3)); // a real number, not text, so it is left alone
        $this->assertSame('2026-10-10', Csv::safe('2026-10-10'));
    }

    public function testCommasQuotesAndNewlinesAreQuoted(): void
    {
        $csv = Csv::build(['Name'], [['Smith, "Bob"'], ["two\nlines"]]);

        $this->assertStringContainsString('"Smith, ""Bob"""', $csv);
        $this->assertStringContainsString("\"two\nlines\"", $csv);
    }
}
