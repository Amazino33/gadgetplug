<?php

declare(strict_types=1);

namespace App\Services\Import;

use Generator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads a CSV or XLSX product file into headers plus rows.
 *
 * Streams rather than loading the file, so a 5,000-row catalogue costs the same
 * memory as a 50-row one. A vendor onboarding their whole shop is the normal
 * case here, not the edge case.
 */
class SpreadsheetReader
{
    /** Files above this are refused outright rather than timing out halfway through. */
    public const MAX_ROWS = 20000;

    /**
     * Column headers, trimmed, in file order. Blank trailing columns - which
     * spreadsheet software adds freely - are dropped.
     *
     * @return array<int, string>
     */
    public function headers(string $path): array
    {
        foreach ($this->rows($path) as $row) {
            $headers = array_map(fn ($cell) => trim((string) $cell), $row);

            while ($headers !== [] && end($headers) === '') {
                array_pop($headers);
            }

            return array_values($headers);
        }

        throw new RuntimeException('That file has no rows at all. Check it saved correctly and try again.');
    }

    /**
     * Every data row keyed by header, header row excluded.
     *
     * @return Generator<int, array<string, string>>  1-based file line number => row
     */
    public function records(string $path): Generator
    {
        $headers = null;
        $line    = 1;

        foreach ($this->rows($path) as $row) {
            if ($headers === null) {
                $headers = array_map(fn ($cell) => trim((string) $cell), $row);

                while ($headers !== [] && end($headers) === '') {
                    array_pop($headers);
                }

                continue;
            }

            $line++;

            $values = array_map(fn ($cell) => trim((string) $cell), $row);

            // A row exported with fewer cells than headers is normal - trailing
            // empties are simply absent. Pad rather than skip, or a product with
            // no description would be dropped as malformed.
            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');

            // Genuinely blank lines carry no product and no error.
            if (implode('', $values) === '') {
                continue;
            }

            yield $line => array_combine($headers, $values);
        }

        if ($headers === null) {
            throw new RuntimeException('That file has no rows at all. Check it saved correctly and try again.');
        }
    }

    /** @return Generator<int, array<int, mixed>> */
    private function rows(string $path): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException('That file could not be opened. Please upload it again.');
        }

        $reader = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv', 'txt' => new CsvReader(),
            'xlsx'       => new XlsxReader(),
            default      => throw new RuntimeException('Only CSV and Excel (.xlsx) files can be imported. Older .xls files must be saved as .xlsx first.'),
        };

        $reader->open($path);

        $seen = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if (++$seen > self::MAX_ROWS) {
                        throw new RuntimeException('That file has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files and import them one at a time.');
                    }

                    yield array_map(
                        fn ($cell) => $cell->getValue(),
                        $row->getCells(),
                    );
                }

                // Only the first sheet. A workbook's later sheets are usually
                // notes or a pivot, and importing them as products would be
                // worse than ignoring them.
                break;
            }
        } finally {
            $reader->close();
        }
    }
}
