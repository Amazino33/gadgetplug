<?php

declare(strict_types=1);

namespace App\Support\Engagement;

/**
 * CSV for the engagement reports' downloads.
 *
 * Business and owner names are typed by vendors, so a cell starting with =,
 * +, -, @ or a tab could run as a formula when the file is opened in a
 * spreadsheet. Such cells get a leading apostrophe, which spreadsheets show
 * as plain text.
 */
final class Csv
{
    /** @param  iterable<array<int, mixed>>  $lines */
    public static function build(array $header, iterable $lines): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(self::cell(...), $header), escape: '\\');

        foreach ($lines as $line) {
            fputcsv($out, array_map(self::cell(...), $line), escape: '\\');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
