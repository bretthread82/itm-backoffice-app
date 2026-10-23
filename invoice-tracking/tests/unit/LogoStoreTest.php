<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogoStore;
use CodeIgniter\HTTP\Files\UploadedFile;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class LogoStoreTest extends AppTestCase
{
    /** @var list<string> */
    private array $stored = [];

    protected function tearDown(): void
    {
        foreach ($this->stored as $name) {
            (new LogoStore())->delete($name);
        }
        parent::tearDown();
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = $this->tempFile($bytes);

        return new UploadedFile($path, $name, null, strlen($bytes), UPLOAD_ERR_OK);
    }

    public function testAcceptsAPng(): void
    {
        $this->assertNull((new LogoStore())->validate($this->upload(self::pngBytes(), 'logo.png')));
    }

    public function testRejectsNonImagesSvgAndDisguisedFiles(): void
    {
        $store = new LogoStore();
        $bad   = [
            'svg'            => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'text as png'    => 'just some text',
            'pdf as png'     => "%PDF-1.4\n%%EOF\n",
            'empty'          => '',
        ];
        foreach ($bad as $label => $bytes) {
            $name = $label === 'svg' ? 'logo.svg' : 'logo.png';
            $this->assertNotNull($store->validate($this->upload($bytes, $name)), $label);
        }
    }

    public function testRejectsOversizeImages(): void
    {
        $big = self::pngBytes() . str_repeat("\0", LogoStore::MAX_BYTES);

        $this->assertStringContainsString('larger than 2 MB', (string) (new LogoStore())->validate($this->upload($big, 'big.png')));
    }

    public function testStoresUnderARandomNameAndDeletes(): void
    {
        $store = new LogoStore();
        $saved = $store->store($this->upload(self::pngBytes(), 'My Logo.png'));
        $this->stored[] = $saved['file'];

        $this->assertSame('image/png', $saved['mime']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $saved['file']);
        $path = LogoStore::path($saved['file']);
        $this->assertFileExists($path);

        $store->delete($saved['file']);
        $this->assertFileDoesNotExist($path);
    }

    public function testPathRefusesAnythingWeDidNotMake(): void
    {
        $this->assertNull(LogoStore::path('../../.env'));
        $this->assertNull(LogoStore::path('logo.png'));
        $this->assertNull(LogoStore::path(null));
        $this->assertNull(LogoStore::path(''));
    }
}
