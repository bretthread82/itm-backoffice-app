<?php

declare(strict_types=1);

namespace App\Core\Payables;

/**
 * CSV for Excel. Text cells that a spreadsheet would treat as a formula (starting with = + - @ or a
 * tab/return) are prefixed with an apostrophe, because supplier names and notes are typed by users.
 * Numbers (int/float) are written as numbers and are not touched.
 */
final class Csv
{
    /**
     * @param list<string>                $headers
     * @param iterable<list<scalar|null>> $rows
     */
    public static function build(array $headers, iterable $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 byte order mark so Excel reads accents correctly
        fputcsv($fh, array_map([self::class, 'safe'], $headers), ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'safe'], $row), ',', '"', '\\');
        }

        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    public static function safe(mixed $value): int|float|string
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $text = (string) ($value ?? '');

        return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }
}
