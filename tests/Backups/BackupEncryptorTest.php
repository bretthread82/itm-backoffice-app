<?php

declare(strict_types=1);

namespace Tests\Backups;

use App\Core\Backups\BackupEncryptor;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class BackupEncryptorTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bkenc' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function make(string $content): string
    {
        $f = $this->dir . '/plain';
        file_put_contents($f, $content);

        return $f;
    }

    private function enc(): BackupEncryptor
    {
        return new BackupEncryptor(1000);
    }

    public function testRoundTripSmallEmptyAndMultiChunk(): void
    {
        foreach (['', 'hello world', random_bytes(1048576), random_bytes(1048576 * 2 + 17)] as $i => $content) {
            $plain = $this->make($content);
            $enc   = $this->dir . "/enc$i";
            $out   = $this->dir . "/out$i";

            $this->enc()->encryptFile($plain, $enc, 'correct horse battery');
            $this->enc()->decryptFile($enc, $out, 'correct horse battery');

            $this->assertTrue(BackupEncryptor::isEncrypted($enc));
            $this->assertSame(hash('sha256', $content), hash_file('sha256', $out), 'case ' . $i);
        }
    }

    public function testCiphertextDoesNotContainPlaintext(): void
    {
        $this->enc()->encryptFile($this->make('SECRET-MARKER-123'), $this->dir . '/e', 'passphrase-long-enough');

        $this->assertStringNotContainsString('SECRET-MARKER-123', (string) file_get_contents($this->dir . '/e'));
    }

    public function testWrongPassphraseFails(): void
    {
        $this->enc()->encryptFile($this->make('data'), $this->dir . '/e', 'right-passphrase-1');

        $this->expectException(RuntimeException::class);
        $this->enc()->decryptFile($this->dir . '/e', $this->dir . '/o', 'wrong-passphrase-1');
    }

    public function testAlteredByteFails(): void
    {
        $this->enc()->encryptFile($this->make(random_bytes(5000)), $this->dir . '/e', 'right-passphrase-1');
        $bytes = (string) file_get_contents($this->dir . '/e');
        $bytes[100] = $bytes[100] ^ "\x01";
        file_put_contents($this->dir . '/e', $bytes);

        $this->expectException(RuntimeException::class);
        $this->enc()->decryptFile($this->dir . '/e', $this->dir . '/o', 'right-passphrase-1');
    }

    public function testTruncationFails(): void
    {
        // Two chunks; cut the file after the first one so every remaining chunk still authenticates.
        $this->enc()->encryptFile($this->make(random_bytes(1048576 + 10)), $this->dir . '/e', 'right-passphrase-1');
        $bytes = (string) file_get_contents($this->dir . '/e');
        file_put_contents($this->dir . '/e', substr($bytes, 0, 34 + 5 + 1048576 + 16));

        $this->expectException(RuntimeException::class);
        $this->enc()->decryptFile($this->dir . '/e', $this->dir . '/o', 'right-passphrase-1');
    }

    public function testRejectsFileThatIsNotABackup(): void
    {
        $this->assertFalse(BackupEncryptor::isEncrypted($this->make('just text')));

        $this->expectException(RuntimeException::class);
        $this->enc()->decryptFile($this->make('just text, long enough to have a header in it......'), $this->dir . '/o', 'x');
    }
}
