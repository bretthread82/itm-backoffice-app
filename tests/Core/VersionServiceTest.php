<?php

namespace Tests\Core;

use App\Core\VersionService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Backoffice;

final class VersionServiceTest extends CIUnitTestCase
{
    public function testBumpResetsLowerParts(): void
    {
        $this->assertSame('2.0.0', VersionService::bump('1.4.7', 'major'));
        $this->assertSame('1.5.0', VersionService::bump('1.4.7', 'minor'));
        $this->assertSame('1.4.8', VersionService::bump('1.4.7', 'bug'));
    }

    public function testBumpRejectsBadInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VersionService::bump('1.4', 'bug');
    }

    public function testPlatformAndEveryModuleHaveAMajorMinorBugVersion(): void
    {
        $this->assertTrue(VersionService::isValid(config(Backoffice::class)->platformVersion));

        foreach (config(Backoffice::class)->modules as $key => $module) {
            $this->assertTrue(VersionService::isValid((string) ($module['version'] ?? '')), "Module {$key} needs a major.minor.bug version.");
        }
    }

    public function testBuildLabelIsAlwaysAvailable(): void
    {
        $this->assertNotSame('', (new VersionService())->build()['label']);
        $this->assertStringStartsWith('v', (new VersionService())->label());
    }
}
