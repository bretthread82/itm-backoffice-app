<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JobLock;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class JobLockTest extends AppTestCase
{
    public function testRunsTheJobAndReturnsItsResult(): void
    {
        $out = (new JobLock())->run('test:basic', static fn (): int => 42);

        $this->assertSame(['ran' => true, 'result' => 42], $out);
    }

    public function testASecondRunWhileTheFirstIsRunningIsSkipped(): void
    {
        $inner = null;
        $outer = (new JobLock())->run('test:overlap', function () use (&$inner): string {
            // A second copy, on its own connection (as another server would have), while the first is still running.
            $inner = (new JobLock())->run('test:overlap', static fn (): string => 'should not run');

            return 'first';
        });

        $this->assertSame(['ran' => true, 'result' => 'first'], $outer);
        $this->assertSame(['ran' => false, 'result' => null], $inner);
    }

    public function testTheLockIsReleasedAfterwardsEvenWhenTheJobThrows(): void
    {
        try {
            (new JobLock())->run('test:throws', static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected the exception to pass through');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue((new JobLock())->run('test:throws', static fn (): bool => true)['ran']);
    }

    public function testDifferentJobsDoNotBlockEachOther(): void
    {
        $inner = null;
        (new JobLock())->run('test:a', function () use (&$inner): void {
            $inner = (new JobLock())->run('test:b', static fn (): int => 1);
        });

        $this->assertTrue($inner['ran']);
    }
}
