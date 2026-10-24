<?php

declare(strict_types=1);

namespace Tests\Backups;

use App\Core\Backups\DestinationFactory;
use App\Core\Backups\Destinations\GraphDestination;
use App\Core\Backups\Destinations\LocalDestination;
use App\Core\Backups\Destinations\S3Destination;
use App\Core\Backups\Destinations\S3Signer;
use App\Core\Backups\Destinations\WebDavDestination;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\Backups\FakeTransport;

/**
 * @internal
 */
final class DestinationsTest extends CIUnitTestCase
{
    private const NAME = 'itm-backup-20261023-020000.sql.gz.enc';

    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bkdest' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'source';
        file_put_contents($this->file, 'backup-bytes');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testS3SignerMatchesAwsDocumentedExample(): void
    {
        // "GET Object" example from the AWS Signature Version 4 documentation for S3.
        $auth = S3Signer::authorization(
            'GET',
            '/test.txt',
            [],
            [
                'host'                 => 'examplebucket.s3.amazonaws.com',
                'range'                => 'bytes=0-9',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'x-amz-date'           => '20130524T000000Z',
            ],
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1',
            '20130524T000000Z',
        );

        $this->assertStringContainsString('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $auth);
        $this->assertStringContainsString('SignedHeaders=host;range;x-amz-content-sha256;x-amz-date', $auth);
    }

    public function testLocalCopiesListsAndDeletes(): void
    {
        $dest = new LocalDestination(['path' => $this->dir . '/target']);
        $dest->put($this->file, self::NAME);

        $this->assertSame([self::NAME], $dest->list());
        $this->assertSame('backup-bytes', file_get_contents($this->dir . '/target/' . self::NAME));

        $dest->delete(self::NAME);
        $this->assertSame([], $dest->list());
    }

    public function testNamesThatCouldEscapeTheFolderAreRefused(): void
    {
        $dest = new LocalDestination(['path' => $this->dir . '/target']);

        $this->expectException(RuntimeException::class);
        $dest->put($this->file, '../../etc/passwd');
    }

    public function testLocalTestWritesAndRemovesProbe(): void
    {
        (new LocalDestination(['path' => $this->dir . '/target']))->test();

        $this->assertSame([], array_diff(scandir($this->dir . '/target') ?: [], ['.', '..']));
    }

    public function testS3SmallUploadIsSignedAndHitsPathStyleUrl(): void
    {
        $http = (new FakeTransport())->on('PUT', 'minio.local:9000/bkt/itm/' . self::NAME, 200);
        $dest = new S3Destination(['endpoint' => 'http://minio.local:9000', 'bucket' => 'bkt', 'prefix' => 'itm', 'access_key' => 'AK', 'secret_key' => 'SK', 'path_style' => true], $http);

        $this->assertSame('bkt/itm/' . self::NAME, $dest->put($this->file, self::NAME));

        $req = $http->requests[0];
        $this->assertSame('backup-bytes', $req['sent']);
        $this->assertSame(hash('sha256', 'backup-bytes'), $req['headers']['x-amz-content-sha256']);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AK/', $req['headers']['Authorization']);
        $this->assertStringNotContainsString('SK', $req['headers']['Authorization']);
    }

    public function testS3ErrorCodeIsExplained(): void
    {
        $http = (new FakeTransport())->on('PUT', '', 403, '<Error><Code>SignatureDoesNotMatch</Code></Error>');
        $dest = new S3Destination(['bucket' => 'bkt', 'region' => 'eu-west-1', 'access_key' => 'AK', 'secret_key' => 'SK'], $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Check the access key');
        $dest->put($this->file, self::NAME);
    }

    public function testS3ListParsesKeysAndPages(): void
    {
        $http = (new FakeTransport())
            ->on('GET', 'bkt.s3.us-east-1.amazonaws.com', 200, '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated><Contents><Key>p/' . self::NAME . '</Key></Contents></ListBucketResult>');
        $dest = new S3Destination(['bucket' => 'bkt', 'region' => 'us-east-1', 'prefix' => 'p', 'access_key' => 'AK', 'secret_key' => 'SK'], $http);

        $this->assertSame([self::NAME], $dest->list());
        $this->assertStringContainsString('prefix=p%2F', $http->requests[0]['url']);
    }

    public function testWebDavCreatesFolderThenUploadsWithBasicAuth(): void
    {
        $http = (new FakeTransport())->on('MKCOL', '/dav', 201)->on('PUT', '/dav/itm/' . self::NAME, 201);
        $dest = new WebDavDestination(['url' => 'https://cloud.test/dav', 'username' => 'u', 'password' => 'p', 'folder' => 'itm'], $http);

        $dest->put($this->file, self::NAME);

        $this->assertSame('MKCOL', $http->requests[0]['method']);
        $this->assertSame('PUT', $http->requests[1]['method']);
        $this->assertSame('Basic ' . base64_encode('u:p'), $http->requests[1]['headers']['Authorization']);
        $this->assertSame('backup-bytes', $http->requests[1]['sent']);
    }

    public function testWebDavListSkipsTheFolderItself(): void
    {
        $xml  = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/dav/itm/</d:href></d:response><d:response><d:href>/dav/itm/' . self::NAME . '</d:href></d:response></d:multistatus>';
        $http = (new FakeTransport())->on('PROPFIND', '/dav/itm/', 207, $xml);
        $dest = new WebDavDestination(['url' => 'https://cloud.test/dav', 'username' => 'u', 'password' => 'p', 'folder' => 'itm'], $http);

        $this->assertSame([self::NAME], $dest->list());
    }

    public function testWebDavRefusedCredentialsGiveAHelpfulMessage(): void
    {
        $http = (new FakeTransport())->on('MKCOL', '', 401);
        $dest = new WebDavDestination(['url' => 'https://cloud.test/dav', 'username' => 'u', 'password' => 'bad', 'folder' => 'itm'], $http);

        $this->expectExceptionMessage('check the user name and password');
        $dest->put($this->file, self::NAME);
    }

    public function testGraphSmallUploadGetsATokenFirst(): void
    {
        $http = (new FakeTransport())
            ->on('POST', 'oauth2/v2.0/token', 200, '{"access_token":"TKN"}')
            ->on('PUT', ':/content', 201);
        $dest = new GraphDestination(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'target_kind' => 'site', 'target' => 'contoso.sharepoint.com:/sites/Finance', 'folder' => 'ITM Backups'], $http);

        $dest->put($this->file, self::NAME);

        $this->assertStringContainsString('client_credentials', (string) $http->requests[0]['body']);
        $put = $http->requests[1];
        $this->assertSame('Bearer TKN', $put['headers']['Authorization']);
        $this->assertStringContainsString('/sites/contoso.sharepoint.com:/sites/Finance:/drive/root:/ITM%20Backups/' . self::NAME . ':/content', $put['url']);
    }

    public function testGraphLargeFileUsesUploadSessionInRanges(): void
    {
        file_put_contents($this->file, str_repeat('x', 4194304 + 100));

        $http = (new FakeTransport())
            ->on('POST', 'oauth2/v2.0/token', 200, '{"access_token":"TKN"}')
            ->on('POST', 'createUploadSession', 200, '{"uploadUrl":"https://upload.test/s1"}')
            ->on('PUT', 'upload.test/s1', 202);
        $dest = new GraphDestination(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'target_kind' => 'user', 'target' => 'a@b.test'], $http);

        $dest->put($this->file, self::NAME);

        $puts = array_values(array_filter($http->requests, static fn ($r) => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame('bytes 0-4194403/4194404', $puts[0]['headers']['Content-Range']);
        $this->assertArrayNotHasKey('Authorization', $puts[0]['headers']);
    }

    public function testGraphSignInFailureIsReported(): void
    {
        $http = (new FakeTransport())->on('POST', 'oauth2/v2.0/token', 401, '{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret.\r\nTrace"}');
        $dest = new GraphDestination(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'target' => 'x'], $http);

        $this->expectExceptionMessage('Invalid client secret');
        $dest->put($this->file, self::NAME);
    }

    public function testFactoryRejectsBadSettings(): void
    {
        $this->expectException(RuntimeException::class);
        (new DestinationFactory())->make('webdav', ['url' => 'ftp://nope']);
    }

    public function testFolderCannotEscape(): void
    {
        $this->expectException(RuntimeException::class);
        new WebDavDestination(['url' => 'https://x.test', 'folder' => 'a/../b'], new FakeTransport());
    }
}
