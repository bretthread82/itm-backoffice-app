<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class HealthTest extends AppTestCase
{
    use FeatureTestTrait;

    public function testReportsOkWithoutSigningIn(): void
    {
        $result = $this->get('health');

        $result->assertStatus(200);
        $this->assertSame(['status' => 'ok'], json_decode($result->getJSON(), true));
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testReportsUnavailableWhenTheFileStoreCannotBeReached(): void
    {
        Services::injectMock('fileStore', new class () implements \App\Services\Files\FileStore {
            public function put(string $key, string $localPath): void
            {
            }

            public function exists(string $key): bool
            {
                throw new \RuntimeException('bucket unreachable: secret detail');
            }

            public function size(string $key): ?int
            {
                return null;
            }

            public function read(string $key): ?string
            {
                return null;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function localPath(string $key): ?string
            {
                return null;
            }

            public function keys(string $prefix): array
            {
                return [];
            }
        });

        $result = $this->get('health');

        $result->assertStatus(503);
        $this->assertSame(['status' => 'unavailable'], json_decode($result->getJSON(), true), 'no detail leaks to the caller');
    }
}
