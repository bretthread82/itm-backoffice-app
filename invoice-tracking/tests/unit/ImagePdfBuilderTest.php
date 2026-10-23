<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImagePdfBuilder;
use App\Services\InvoiceFiles;
use App\Services\WorkflowException;
use Tests\Support\AppTestCase;

final class ImagePdfBuilderTest extends AppTestCase
{
    private function jpeg(int $w = 40, int $h = 60): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagejpeg($im);

        return $this->tempFile((string) ob_get_clean());
    }

    public function testSinglePhotoBecomesAValidOnePagePdf(): void
    {
        $pdf = (new ImagePdfBuilder())->build([$this->jpeg()]);
        $this->createdFiles[] = $pdf;

        (new InvoiceFiles($this->db))->validate($pdf, 'invoice-photo.pdf');   // same checks as an uploaded PDF
        $data = (string) file_get_contents($pdf);
        $this->assertStringStartsWith('%PDF-', $data);
        $this->assertStringContainsString('/Count 1', $data);
        $this->assertStringContainsString('/DCTDecode', $data);
    }

    public function testPhotosBecomeOnePagePerImageIncludingPngWithTransparency(): void
    {
        $im = imagecreatetruecolor(30, 30);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start();
        imagepng($im);
        $png = $this->tempFile((string) ob_get_clean());

        $pdf = (new ImagePdfBuilder())->build([$this->jpeg(), $png, $this->jpeg(60, 40)]);
        $this->createdFiles[] = $pdf;

        $this->assertStringContainsString('/Count 3', (string) file_get_contents($pdf));
    }

    public function testLargePhotosAreScaledDown(): void
    {
        $pdf = (new ImagePdfBuilder())->build([$this->jpeg(4000, 3000)]);
        $this->createdFiles[] = $pdf;

        $this->assertMatchesRegularExpression('#/Width 2200 /Height 1650#', (string) file_get_contents($pdf));
    }

    public function testNonImagesAreRefused(): void
    {
        $this->expectException(WorkflowException::class);
        (new ImagePdfBuilder())->build([$this->pdfFile()]);
    }

    public function testTextRenamedToJpgIsRefused(): void
    {
        $this->expectException(WorkflowException::class);
        (new ImagePdfBuilder())->build([$this->tempFile('not an image at all')]);
    }

    public function testNoPhotosAndTooManyPhotosAreRefused(): void
    {
        $builder = new ImagePdfBuilder();
        try {
            $builder->build([]);
            $this->fail('Expected an exception for no photos.');
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(WorkflowException::class);
        $builder->build(array_fill(0, ImagePdfBuilder::MAX_PAGES + 1, $this->jpeg()));
    }
}
