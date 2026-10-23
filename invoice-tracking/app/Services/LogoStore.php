<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Company and group logos. PNG, JPG and WebP only (no SVG: it can carry script). Files live under
 * writable/uploads/logos, never in public/, and are served through an access-checked route.
 * Not recorded in invoice_files, so the file purge never touches them.
 */
class LogoStore
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** image type => [mime, extension] */
    private const TYPES = [
        IMAGETYPE_PNG  => ['image/png', 'png'],
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    public static function directory(): string
    {
        return WRITEPATH . 'uploads/logos';
    }

    public const KEY_PREFIX = 'logos/';

    /** The name itself when it is one we would have made, otherwise null. */
    public static function validName(?string $name): ?string
    {
        $name = basename((string) $name);

        return preg_match('/^[a-f0-9]{32}\.(png|jpg|webp)$/', $name) === 1 ? $name : null;
    }

    /** Full path in the local driver's folder, or null for an invalid name. Use read() to get the contents on any driver. */
    public static function path(?string $name): ?string
    {
        $name = self::validName($name);

        return $name === null ? null : self::directory() . '/' . $name;
    }

    /** The logo's bytes on whichever driver is configured, or null when the name is invalid or the file is missing. */
    public function read(?string $name): ?string
    {
        $name = self::validName($name);

        return $name === null ? null : service('fileStore')->read(self::KEY_PREFIX . $name);
    }

    /**
     * @return string|null a message when the upload is not acceptable, null when it is fine
     */
    public function validate(UploadedFile $file): ?string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'The logo did not upload: ' . $file->getErrorString();
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return 'The logo is larger than ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.';
        }

        $info = @getimagesize($file->getTempName());
        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            return 'The logo must be a PNG, JPG or WebP image.';
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName());
        if ($mime !== self::TYPES[$info[2]][0]) {
            return 'The logo must be a PNG, JPG or WebP image.';
        }

        return null;
    }

    /**
     * Call validate() first.
     *
     * @return array{file: string, mime: string}
     */
    public function store(UploadedFile $file): array
    {
        $info = getimagesize($file->getTempName());
        [$mime, $ext] = self::TYPES[$info[2]];

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        service('fileStore')->put(self::KEY_PREFIX . $name, $file->getTempName());

        return ['file' => $name, 'mime' => $mime];
    }

    public function delete(?string $name): void
    {
        $name = self::validName($name);
        if ($name !== null) {
            service('fileStore')->delete(self::KEY_PREFIX . $name);
        }
    }
}
