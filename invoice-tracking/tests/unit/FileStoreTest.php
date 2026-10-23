<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Files\FileMigrator;
use App\Services\Files\LocalFileStore;
use App\Services\Files\S3FileStore;
use Aws\Result;
use Aws\S3\S3Client;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileStoreTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($dir . '/*') ?: [] as $d) {
                @rmdir($d);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function root(): string
    {
        $dir = sys_get_temp_dir() . '/filestore_' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function source(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;

        return $path;
    }

    public function testLocalStoreRoundTrip(): void
    {
        $store = new LocalFileStore($this->root());
        $key   = 'invoices/' . str_repeat('a', 32) . '.pdf';

        $this->assertFalse($store->exists($key));
        $this->assertNull($store->read($key));
        $this->assertNull($store->localPath($key));
        $this->assertNull($store->size($key));

        $store->put($key, $this->source('%PDF-1.4 hello'));

        $this->assertTrue($store->exists($key));
        $this->assertSame('%PDF-1.4 hello', $store->read($key));
        $this->assertSame(14, $store->size($key));
        $this->assertSame('%PDF-1.4 hello', file_get_contents((string) $store->localPath($key)));
        $this->assertSame([$key], $store->keys('invoices'));

        $this->assertTrue($store->delete($key));
        $this->assertFalse($store->exists($key));
        $this->assertTrue($store->delete($key), 'deleting a missing file counts as gone');
    }

    /**
     * @dataProvider badKeys
     */
    public function testKeysCannotEscapeTheirFolder(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LocalFileStore($this->root()))->exists($key);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badKeys(): array
    {
        return [
            'parent'   => ['invoices/../../.env'],
            'absolute' => ['/etc/passwd'],
            'nested'   => ['invoices/a/b.pdf'],
            'no folder' => ['x.pdf'],
            'dotfile'  => ['invoices/.htaccess'],
            'windows'  => ['invoices\\x.pdf'],
        ];
    }

    private function s3(array &$calls, array $responses): S3FileStore
    {
        $client = new S3Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler'     => static function ($command) use (&$calls, &$responses) {
                $calls[] = [$command->getName(), $command['Bucket'], $command['Key'] ?? $command['Prefix'] ?? null];
                $next = array_shift($responses);

                return \GuzzleHttp\Promise\Create::promiseFor($next);
            },
        ]);

        return new S3FileStore($client, 'bkt', 'prod');
    }

    public function testS3StorePrefixesKeysAndReadsBack(): void
    {
        $calls = [];
        $store = $this->s3($calls, [
            new Result([]),                                                        // put
            new Result(['Body' => \GuzzleHttp\Psr7\Utils::streamFor('%PDF-x')]),    // read
            new Result([]),                                                        // delete
        ]);
        $key = 'invoices/' . str_repeat('b', 32) . '.pdf';

        $store->put($key, $this->source('%PDF-x'));
        $this->assertSame('%PDF-x', $store->read($key));
        $this->assertTrue($store->delete($key));

        $this->assertSame(['PutObject', 'bkt', 'prod/' . $key], $calls[0]);
        $this->assertSame(['GetObject', 'bkt', 'prod/' . $key], $calls[1]);
        $this->assertSame(['DeleteObject', 'bkt', 'prod/' . $key], $calls[2]);
    }

    public function testS3StoreRejectsBadKeysBeforeAnyCall(): void
    {
        $calls = [];
        $store = $this->s3($calls, []);

        try {
            $store->read('invoices/../secret');
            $this->fail('expected an exception');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $calls);
        }
    }

    public function testMigratorCopiesVerifiesAndIsRepeatable(): void
    {
        $from = new LocalFileStore($this->root());
        $to   = new LocalFileStore($this->root());
        $a    = 'invoices/' . str_repeat('c', 32) . '.pdf';
        $b    = 'logos/' . str_repeat('d', 32) . '.png';
        $from->put($a, $this->source('one'));
        $from->put($b, $this->source('two'));

        $dry = (new FileMigrator($from, $to))->run(true);
        $this->assertSame(2, $dry['copied']);
        $this->assertFalse($to->exists($a), 'dry run copies nothing');

        $first = (new FileMigrator($from, $to))->run();
        $this->assertSame(['copied' => 2, 'skipped' => 0, 'failed' => []], $first);
        $this->assertSame('one', $to->read($a));
        $this->assertTrue($from->exists($a), 'the source is never deleted');

        $again = (new FileMigrator($from, $to))->run();
        $this->assertSame(['copied' => 0, 'skipped' => 2, 'failed' => []], $again);
    }
}
