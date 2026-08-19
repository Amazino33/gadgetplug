<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Product;
use App\Models\Vendor;
use App\Support\Import\ProductField;
use Illuminate\Support\Collection;

/**
 * Reads the whole file and works out, for every row, exactly what would happen -
 * without touching the database.
 *
 * Everything the vendor is shown before they confirm comes from here: the
 * preview table, the error list, and the "X new, Y updated, Z skipped" summary.
 * Preparing and committing are separate on purpose. A vendor must be able to see
 * that their file would archive 200 products before it archives them.
 */
class ImportPreparer
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly RowParser $parser,
    ) {
    }

    /**
     * @param  array<string, string>  $mapping  header => ProductField value
     * @return Collection<int, ParsedRow>
     */
    public function prepare(string $path, array $mapping, Vendor $vendor): Collection
    {
        $mapping = $this->usableMapping($mapping);

        // Loaded once rather than queried per row: a 2,000-row file would
        // otherwise fire 4,000 lookups to answer questions two indexed columns
        // can answer in one pass each.
        $bySku     = $this->existingIndex($vendor, 'sku');
        $byBarcode = $this->existingIndex($vendor, 'barcode');

        $seenSku     = [];
        $seenBarcode = [];

        $rows = collect();

        foreach ($this->reader->records($path) as $line => $record) {
            [$values, $errors, $warnings] = $this->parser->parse($record, $mapping);

            $name    = trim((string) ($values[ProductField::Name->value] ?? ''));
            $sku     = trim((string) ($values[ProductField::Sku->value] ?? ''));
            $barcode = trim((string) ($values[ProductField::Barcode->value] ?? ''));

            if ($name === '') {
                $errors[] = 'Name is missing. Every product needs one.';
            }

            // Without one of these there is nothing stable to match on, so a
            // second import of the same file would create the whole catalogue
            // over again.
            if ($sku === '' && $barcode === '') {
                $errors[] = 'Neither SKU nor barcode is set, so this product could not be matched on a later import.';
            }

            // Duplicates inside the file itself. The first occurrence is kept
            // and the later ones refused - merging them would silently pick one
            // row's price over another's with no way to tell which won.
            if ($sku !== '' && isset($seenSku[$sku])) {
                $errors[] = sprintf('SKU "%s" is already used on line %d of this file.', $sku, $seenSku[$sku]);
            } elseif ($sku !== '') {
                $seenSku[$sku] = $line;
            }

            if ($barcode !== '' && isset($seenBarcode[$barcode])) {
                $errors[] = sprintf('Barcode "%s" is already used on line %d of this file.', $barcode, $seenBarcode[$barcode]);
            } elseif ($barcode !== '') {
                $seenBarcode[$barcode] = $line;
            }

            // SKU first, barcode second - the spec's order, and the right one:
            // a SKU is chosen by the business, a barcode is whatever the
            // manufacturer printed, and two different products can legitimately
            // carry the same manufacturer barcode.
            $matchedId = null;

            if ($sku !== '' && isset($bySku[$sku])) {
                $matchedId = $bySku[$sku];
            } elseif ($barcode !== '' && isset($byBarcode[$barcode])) {
                $matchedId = $byBarcode[$barcode];
            }

            // A row can name two identifiers that belong to two different
            // products: its SKU matches one, its barcode another. Applying it
            // would move an identifier off the product that holds it, which the
            // unique index refuses - and a database error mid-run aborts the
            // whole import with nothing explaining why. Refuse the one row here
            // instead, and say which products are in the way.
            foreach ([[ProductField::Sku, $sku, $bySku], [ProductField::Barcode, $barcode, $byBarcode]] as [$field, $identifier, $index]) {
                if ($identifier === '' || ! isset($index[$identifier])) {
                    continue;
                }

                if ($matchedId !== null && $index[$identifier] !== $matchedId) {
                    $errors[] = sprintf(
                        '%s "%s" already belongs to a different product (#%d), while this row matches product #%d. Correct one of them and import again.',
                        $field->label(),
                        $identifier,
                        $index[$identifier],
                        $matchedId,
                    );
                }
            }

            if ($matchedId !== null && ($values[ProductField::Status->value] ?? null) === 'archived') {
                $warnings[] = 'This will archive a product you already sell.';
            }

            $rows->push(new ParsedRow(
                line: $line,
                values: $values,
                errors: $errors,
                warnings: $warnings,
                matchedProductId: $matchedId,
                raw: $record,
            ));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ParsedRow>  $rows
     * @return array{total: int, create: int, update: int, skip: int}
     */
    public function summarise(Collection $rows): array
    {
        return [
            'total'  => $rows->count(),
            'create' => $rows->where(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_CREATE)->count(),
            'update' => $rows->where(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_UPDATE)->count(),
            'skip'   => $rows->where(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_SKIP)->count(),
        ];
    }

    /**
     * Drops blank selections and anything pointing at a field an import may not
     * write, so a tampered form cannot reach Quantity through the back door.
     *
     * @param  array<string, string|null>  $mapping
     * @return array<string, string>
     */
    private function usableMapping(array $mapping): array
    {
        return collect($mapping)
            ->filter(fn ($field) => filled($field))
            ->filter(fn ($field) => ProductField::tryFrom((string) $field)?->isImportable() === true)
            ->map(fn ($field) => (string) $field)
            ->all();
    }

    /** @return array<string, int>  identifier => product id */
    private function existingIndex(Vendor $vendor, string $column): array
    {
        return Product::query()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            // Descending, because pluck lets a later row overwrite an earlier
            // one on key collision - so the lowest id is assigned last and wins.
            // Oldest wins when the catalogue already holds duplicates, so a
            // repeated import keeps landing on the same product instead of
            // wandering between them. products:check-duplicates reports these.
            ->orderByDesc('id')
            ->pluck('id', $column)
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
