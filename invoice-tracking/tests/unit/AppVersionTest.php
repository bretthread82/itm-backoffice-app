<?php

declare(strict_types=1);

use App\Services\AppVersion;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AppVersionTest extends CIUnitTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'ver');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testBumpsZeroTheLowerPartsAndAlwaysAddOneToTheBuild(): void
    {
        $v = new AppVersion(1, 4, 7, 20);

        $this->assertSame('2.0.0 (build 21)', $v->bump('major')->full());
        $this->assertSame('1.5.0 (build 21)', $v->bump('minor')->full());
        $this->assertSame('1.4.8 (build 21)', $v->bump('bugfix')->full());
        $this->assertSame('1.4.7 (build 21)', $v->bump('build')->full());
    }

    public function testRejectsAnUnknownPart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AppVersion(1, 0, 0, 1))->bump('patch');
    }

    public function testSavesAndLoadsRoundTrip(): void
    {
        (new AppVersion(3, 2, 1, 99))->save($this->file);

        $this->assertSame('3.2.1 (build 99)', AppVersion::load($this->file)->full());
    }

    public function testMissingOrMalformedFileFallsBackInsteadOfBreaking(): void
    {
        file_put_contents($this->file, '{"version":"banana"}');
        $this->assertSame('0.0.0 (build 0)', AppVersion::load($this->file)->full());
        $this->assertSame('0.0.0 (build 0)', AppVersion::load($this->file . '.none')->full());
    }

    public function testShippedVersionFileIsValid(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AppVersion::load()->version());
        $this->assertGreaterThan(0, AppVersion::load()->build);
    }
}
