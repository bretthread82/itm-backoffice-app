<?php

declare(strict_types=1);

namespace App\Core\Invoices;

/**
 * Turns photos of a paper invoice (one per page) into a single PDF, so everything downstream of the upload
 * (approval, preview, SharePoint, purge, API) only ever deals with PDFs. Each photo is checked as a real image,
 * turned upright, scaled down and re-encoded as JPEG (which also drops EXIF/GPS data), then embedded as one page.
 * No PDF library is needed: the file is a handful of plain objects.
 */
final class ImagePdfBuilder
{
    public const MAX_PAGES = 20;

    private const MAX_PIXELS    = 50_000_000;   // what GD can decode without running out of memory
    private const LONG_EDGE_PX  = 2200;
    private const JPEG_QUALITY  = 85;
    private const PAGE_LONG_PT  = 842.0;        // A4 in points
    private const PAGE_SHORT_PT = 595.0;
    private const TYPES         = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    /**
     * @param list<string> $paths image files, in page order
     *
     * @return string path of a new temporary PDF (the caller deletes it)
     *
     * @throws WorkflowException
     */
    public function build(array $paths): string
    {
        if (! extension_loaded('gd')) {
            throw new WorkflowException('Photos cannot be converted on this server (the GD extension is missing). Please upload a PDF.');
        }
        if ($paths === []) {
            throw new WorkflowException('No photos were chosen.');
        }
        if (count($paths) > self::MAX_PAGES) {
            throw new WorkflowException('Please upload at most ' . self::MAX_PAGES . ' photos per invoice.');
        }

        // The upload check refuses PDFs containing names like /JS. Compressed image bytes can contain such a
        // sequence by chance (rarely), so re-encode at a slightly different quality until it is clean.
        for ($q = self::JPEG_QUALITY, $pdf = ''; $q > self::JPEG_QUALITY - 4; $q--) {
            $pages = [];
            foreach ($paths as $path) {
                $pages[] = $this->normalise($path, $q);
            }
            $pdf = $this->pdf($pages);
            $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $pdf) ?? $pdf;
            if (preg_match('#/(JavaScript|JS|Launch|RichMedia|SubmitForm|ImportData|GoToR|GoToE|Encrypt)(?![A-Za-z0-9])#', $names) !== 1) {
                break;
            }
        }

        $out = tempnam(sys_get_temp_dir(), 'inv');
        if ($out === false || file_put_contents($out, $pdf) === false) {
            throw new WorkflowException('The photos could not be converted to a PDF.');
        }

        return $out;
    }

    /**
     * @return array{jpeg: string, w: int, h: int}
     *
     * @throws WorkflowException
     */
    private function normalise(string $path, int $quality): array
    {
        $maxBytes = max(1, (int) setting('InvoiceSettings.maxUploadMb')) * 1024 * 1024;
        $size     = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            throw new WorkflowException('A photo is empty or could not be read.');
        }
        if ($size > $maxBytes) {
            throw new WorkflowException('A photo is larger than ' . (int) setting('InvoiceSettings.maxUploadMb') . ' MB.');
        }

        $info = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($info === false || ! isset(self::TYPES[$info[2]]) || $mime !== self::TYPES[$info[2]]) {
            throw new WorkflowException('Photos must be JPEG, PNG or WebP images.');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new WorkflowException('A photo is too large to process. Please use a lower camera resolution.');
        }

        $img = @imagecreatefromstring((string) file_get_contents($path));
        if ($img === false) {
            throw new WorkflowException('A photo could not be read. Please try again.');
        }

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif  = @exif_read_data($path);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => 180, 6 => 270, 8 => 90, default => 0,
            };
            if ($angle !== 0 && ($rot = imagerotate($img, $angle, 0)) !== false) {
                $img = $rot;
            }
        }

        $w     = imagesx($img);
        $h     = imagesy($img);
        $scale = min(1.0, self::LONG_EDGE_PX / max($w, $h));
        $nw    = max(1, (int) round($w * $scale));
        $nh    = max(1, (int) round($h * $scale));

        // Flatten onto white (transparent PNG/WebP would otherwise turn black) while scaling.
        $flat = imagecreatetruecolor($nw, $nh);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopyresampled($flat, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($flat, null, $quality);
        $jpeg = (string) ob_get_clean();

        return ['jpeg' => $jpeg, 'w' => $nw, 'h' => $nh];
    }

    /**
     * @param list<array{jpeg: string, w: int, h: int}> $pages
     */
    private function pdf(array $pages): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids    = [];
        $n       = 3;

        foreach ($pages as $i => $p) {
            [$pageId, $contentId, $imageId] = [$n, $n + 1, $n + 2];
            $n += 3;

            // Fit the image on an A4-sized area, keeping its shape; the page is exactly the image.
            $long  = max($p['w'], $p['h']);
            $short = min($p['w'], $p['h']);
            $fit   = min(self::PAGE_LONG_PT / $long, self::PAGE_SHORT_PT / $short);
            $pw    = round($p['w'] * $fit, 2);
            $ph    = round($p['h'] * $fit, 2);

            $stream = sprintf('q %s 0 0 %s 0 0 cm /Im%d Do Q', $pw, $ph, $i);

            $kids[]              = $pageId . ' 0 R';
            $objects[$pageId]    = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im%d %d 0 R >> >> /Contents %d 0 R >>', $pw, $ph, $i, $imageId, $contentId);
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$imageId]   = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $p['w'], $p['h'], strlen($p['jpeg'])) . $p['jpeg'] . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $out     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }

        return $out . 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
