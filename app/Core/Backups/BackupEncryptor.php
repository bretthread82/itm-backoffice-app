<?php

declare(strict_types=1);

namespace App\Core\Backups;

use RuntimeException;

/**
 * Streaming file encryption for backups: AES-256-GCM in 1 MiB chunks, so a large dump never has to fit in memory.
 *
 * File layout: "ITMBK1" | iterations (u32) | salt (16) | nonce prefix (8) | chunks.
 * Each chunk is: final flag (1 byte) | length (u32) | ciphertext | GCM tag (16). The nonce is the prefix plus the
 * chunk counter, and the header and flag are authenticated, so reordered, altered or truncated files fail to decrypt.
 * The key comes from the passphrase (PBKDF2-SHA256) and is independent of the application encryption key.
 */
final class BackupEncryptor
{
    public const MAGIC = 'ITMBK1';

    private const CIPHER     = 'aes-256-gcm';
    private const CHUNK_SIZE = 1048576;
    private const TAG_LENGTH = 16;
    private const HEADER_LEN = 34;

    public function __construct(private readonly int $iterations = 200000)
    {
    }

    public function encryptFile(string $source, string $target, string $passphrase): void
    {
        $this->requirePassphrase($passphrase);

        $in = $this->open($source, 'rb');
        $out = $this->open($target, 'wb');

        try {
            $salt   = random_bytes(16);
            $prefix = random_bytes(8);
            $header = self::MAGIC . pack('N', $this->iterations) . $salt . $prefix;
            $key    = $this->key($passphrase, $salt, $this->iterations);

            $this->write($out, $header);

            $counter = 0;
            $chunk   = $this->read($in, self::CHUNK_SIZE);

            do {
                // Read ahead so the last chunk can be flagged as final (a file that is an exact multiple of the chunk size too).
                $next  = $this->read($in, self::CHUNK_SIZE);
                $final = $next === '';
                $flag  = $final ? "\x01" : "\x00";
                $tag   = '';
                $cipher = openssl_encrypt($chunk, self::CIPHER, $key, OPENSSL_RAW_DATA, $prefix . pack('N', $counter), $tag, $header . $flag, self::TAG_LENGTH);

                if ($cipher === false) {
                    throw new RuntimeException('Encryption failed.');
                }

                $this->write($out, $flag . pack('N', strlen($chunk)) . $cipher . $tag);
                $chunk = $next;
                $counter++;
            } while (! $final);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decryptFile(string $source, string $target, string $passphrase): void
    {
        $this->requirePassphrase($passphrase);

        $in = $this->open($source, 'rb');
        $out = $this->open($target, 'wb');

        try {
            $header = $this->read($in, self::HEADER_LEN);

            if (strlen($header) !== self::HEADER_LEN || ! str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('This is not an ITM backup file.');
            }

            $iterations = (int) unpack('N', substr($header, 6, 4))[1];
            $salt       = substr($header, 10, 16);
            $prefix     = substr($header, 26, 8);
            $key        = $this->key($passphrase, $salt, $iterations);
            $counter    = 0;

            do {
                $head = $this->read($in, 5);

                if (strlen($head) !== 5) {
                    throw new RuntimeException('The backup file is truncated.');
                }

                $flag   = $head[0];
                $length = (int) unpack('N', substr($head, 1))[1];

                if ($length > self::CHUNK_SIZE) {
                    throw new RuntimeException('The backup file is damaged.');
                }

                $body = $this->read($in, $length + self::TAG_LENGTH);

                if (strlen($body) !== $length + self::TAG_LENGTH) {
                    throw new RuntimeException('The backup file is truncated.');
                }

                $plain = openssl_decrypt(substr($body, 0, $length), self::CIPHER, $key, OPENSSL_RAW_DATA, $prefix . pack('N', $counter), substr($body, $length), $header . $flag);

                if ($plain === false) {
                    throw new RuntimeException('Could not decrypt: wrong passphrase, or the file has been altered.');
                }

                $this->write($out, $plain);
                $counter++;
            } while ($flag !== "\x01");

            if (! feof($in) && $this->read($in, 1) !== '') {
                throw new RuntimeException('The backup file has unexpected data at the end.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function isEncrypted(string $path): bool
    {
        $h = @fopen($path, 'rb');

        if (! $h) {
            return false;
        }

        $magic = (string) fread($h, strlen(self::MAGIC));
        fclose($h);

        return $magic === self::MAGIC;
    }

    private function key(string $passphrase, string $salt, int $iterations): string
    {
        if ($iterations < 1000) {
            throw new RuntimeException('The backup file is damaged.');
        }

        return hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    }

    private function requirePassphrase(string $passphrase): void
    {
        if ($passphrase === '') {
            throw new RuntimeException('A backup passphrase is needed (backups.passphrase in .env).');
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $h = @fopen($path, $mode);

        if (! $h) {
            throw new RuntimeException('Cannot open ' . $path . '.');
        }

        return $h;
    }

    /**
     * Reads exactly $length bytes unless the file ends first.
     *
     * @param resource $h
     */
    private function read($h, int $length): string
    {
        $data = '';

        while (strlen($data) < $length && ! feof($h)) {
            $part = fread($h, $length - strlen($data));

            if ($part === false || $part === '') {
                break;
            }

            $data .= $part;
        }

        return $data;
    }

    /**
     * @param resource $h
     */
    private function write($h, string $data): void
    {
        if ($data !== '' && fwrite($h, $data) !== strlen($data)) {
            throw new RuntimeException('Could not write the backup file (disk full?).');
        }
    }
}
