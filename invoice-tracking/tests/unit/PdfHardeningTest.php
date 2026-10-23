<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InvoiceFiles;
use App\Services\WorkflowException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class PdfHardeningTest extends AppTestCase
{
    private function pdf(string $body, bool $eof = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'h') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog {$body} >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n" . ($eof ? "%%EOF\n" : ''));
        $this->createdFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        setting()->set('AppSettings.scanCommand', '');
        parent::tearDown();
    }

    public function testPlainPdfIsAccepted(): void
    {
        (new InvoiceFiles($this->db))->validate($this->pdf(''), 'a.pdf');
        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider badBodies
     */
    public function testActiveOrUnreadablePdfsAreRefused(string $body, bool $eof): void
    {
        $this->expectException(WorkflowException::class);
        (new InvoiceFiles($this->db))->validate($this->pdf($body, $eof), 'a.pdf');
    }

    public static function badBodies(): array
    {
        return [
            'javascript'        => ['/OpenAction << /S /JavaScript /JS (app.alert(1)) >>', true],
            'obfuscated name'   => ['/OpenAction << /S /J#61vaScr#69pt >>', true],
            'launch'            => ['/OpenAction << /S /Launch /F (cmd.exe) >>', true],
            'encrypted'         => ['/Encrypt 5 0 R', true],
            'truncated, no EOF' => ['', false],
        ];
    }

    public function testScannerExitCodeDecides(): void
    {
        $php = escapeshellarg(PHP_BINARY);
        $f   = new InvoiceFiles($this->db);

        setting()->set('AppSettings.scanCommand', $php . ' -r "exit(0);" {file}');
        $f->validate($this->pdf(''), 'a.pdf');

        setting()->set('AppSettings.scanCommand', $php . ' -r "exit(1);" {file}');
        $this->expectException(WorkflowException::class);
        $f->validate($this->pdf(''), 'a.pdf');
    }

    public function testMissingScannerFailsClosed(): void
    {
        setting()->set('AppSettings.scanCommand', 'definitely-not-a-real-scanner-xyz {file}');

        $this->expectException(WorkflowException::class);
        (new InvoiceFiles($this->db))->validate($this->pdf(''), 'a.pdf');
    }
}
