<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductBarcode;
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
    ) {}

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
        $bySku = $this->existingIndex($vendor, 'sku');
        $byBarcode = $this->existingIndex($vendor, 'barcode');
        $names = Product::where('vendor_id', $vendor->id)->pluck('name', 'id')->all();

        // Every code the shop already answers to — main, extra and unit
        // barcodes — and which product holds it.
        $codeOwners = ProductBarcode::where('vendor_id', $vendor->id)
            ->pluck('product_id', 'barcode')
            ->map(fn ($id) => (int) $id)
            ->all() + $byBarcode;

        $seenSku = [];
        $seenBarcode = [];
        // Extra and unit barcodes already claimed earlier in this file.
        $seenCode = [];

        $rows = collect();

        foreach ($this->reader->records($path) as $line => $record) {
            [$values, $errors, $warnings] = $this->parser->parse($record, $mapping);

            $name = trim((string) ($values[ProductField::Name->value] ?? ''));
            $sku = trim((string) ($values[ProductField::Sku->value] ?? ''));
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

            // The exact shape of the incident that overwrote 134 real
            // products: a SKU or barcode that a small placeholder scheme (or
            // a generic manufacturer part number — a battery's own model code,
            // reused across completely different phones) happens to also
            // carry on an unrelated existing product. Matching still succeeds
            // and the update still happens — this is a warning, not a block,
            // since a genuine rename looks identical from here — but it puts
            // the collision in front of the vendor before they confirm,
            // instead of leaving it to be discovered as "the price keeps
            // changing" weeks later.
            if ($matchedId !== null && $name !== '' && isset($names[$matchedId])) {
                $existingName = (string) $names[$matchedId];

                if (! $this->namesLookRelated($name, $existingName)) {
                    $warnings[] = sprintf(
                        'This matches an existing product named "%s" (#%d), but the names look unrelated. If this is actually a different product, a shared SKU/barcode will silently overwrite "%s" instead of creating a new one.',
                        $existingName,
                        $matchedId,
                        $existingName,
                    );
                }
            }

            // products.price is NOT NULL with no default, so a new product
            // without one cannot be written at all. A blank cell raises no
            // parse error — RowParser rightly reads an empty cell as "column
            // not supplied" rather than bad data — so without this check the
            // row previews as a healthy Create and then kills the whole import
            // on a raw SQL error, one row at a time, however many there are.
            //
            // Checked here rather than in RowParser because only this point
            // knows whether the row is a create: an update with no price column
            // must leave the existing price alone, exactly as the category
            // fallback in ProductImporter only applies to new products.
            if ($matchedId === null && ! isset($values[ProductField::Price->value])) {
                $errors[] = 'Price is missing. A new product needs one, because it cannot be sold without a price.';
            }

            // ── Selling units and extra barcodes ──
            // Problems here never stop the product: the unit or barcode at
            // fault is left out and the row says why.
            [, $unitWarnings] = self::unitsFor($values);
            $warnings = [...$warnings, ...$unitWarnings];

            // A main barcode another product already carries as a unit or
            // extra code. Kept off this product rather than stealing it.
            if ($barcode !== '' && ! isset($byBarcode[$barcode])
                && isset($codeOwners[$barcode]) && $codeOwners[$barcode] !== $matchedId) {
                $warnings[] = self::barcodeTakenMessage($barcode);
                unset($values[ProductField::Barcode->value]);

                if ($sku === '') {
                    $errors[] = 'Barcode "'.$barcode.'" belongs to another product and there is no SKU, so this row has nothing to be matched on.';
                }
            }

            foreach (self::extraCodesIn($values) as $code) {
                if (isset($codeOwners[$code]) && $codeOwners[$code] !== $matchedId) {
                    $warnings[] = self::barcodeTakenMessage($code);
                } elseif ((isset($seenCode[$code]) && $seenCode[$code] !== $line)
                    || (isset($seenBarcode[$code]) && $seenBarcode[$code] !== $line)) {
                    $warnings[] = sprintf('Barcode "%s" is already used on line %d of this file, so it was skipped here.', $code, $seenCode[$code] ?? $seenBarcode[$code]);
                } else {
                    $seenCode[$code] = $line;
                }
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
     * The extra units a row describes, and why any were left out.
     *
     * Shared with ProductImporter so the preview and the write agree on what a
     * row means. A unit needs a name and a whole-number "Contains" of 2 or
     * more; a price of 0 (or none, for a new unit) saves it switched off.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<int, array{slot: int, name: string, contains: int, price: float|null, wholesale: float|null, barcode: string, active: bool}>, 1: array<int, string>}
     */
    public static function unitsFor(array $values): array
    {
        $units = [];
        $warnings = [];
        $names = [];

        foreach (ProductField::UNIT_SLOTS as $n) {
            $f = ProductField::unitFields($n);
            $name = trim((string) ($values[$f['name']->value] ?? ''));

            if ($name === '') {
                $others = array_filter([$f['contains'], $f['price'], $f['wholesale'], $f['barcode']], fn ($field) => filled($values[$field->value] ?? null));

                if ($others !== []) {
                    $warnings[] = "Unit {$n} has values but no name, so it was skipped.";
                }

                continue;
            }

            $label = "Unit {$n} ({$name})";
            $rawCount = trim((string) ($values[$f['contains']->value] ?? ''));

            if (! preg_match('/^\d+(\.0+)?$/', $rawCount) || (int) $rawCount < 2) {
                $warnings[] = sprintf(
                    '%s was skipped: Contains must be a whole number of 2 or more (got "%s").',
                    $label,
                    $rawCount === '' ? 'blank' : $rawCount,
                );

                continue;
            }

            if (isset($names[mb_strtolower($name)])) {
                $warnings[] = "{$label} was skipped: another unit on this row has the same name.";

                continue;
            }

            $names[mb_strtolower($name)] = true;

            $price = $values[$f['price']->value] ?? null;

            if ($price === null) {
                $warnings[] = "{$label} has no price: a new unit is saved switched off, an existing one keeps its price.";
            } elseif ((float) $price <= 0) {
                $warnings[] = "{$label} has a price of 0, so it is saved switched off.";
            }

            $units[] = [
                'slot' => $n,
                'name' => mb_substr($name, 0, 64),
                'contains' => (int) $rawCount,
                'price' => $price === null ? null : (float) $price,
                'wholesale' => isset($values[$f['wholesale']->value]) ? (float) $values[$f['wholesale']->value] : null,
                'barcode' => trim((string) ($values[$f['barcode']->value] ?? '')),
                'active' => $price !== null && (float) $price > 0,
            ];
        }

        return [$units, $warnings];
    }

    /** Alt Barcode and every unit barcode on the row, in column order. */
    public static function extraCodesIn(array $values): array
    {
        $fields = [ProductField::AltBarcode, ProductField::Unit2Barcode, ProductField::Unit3Barcode, ProductField::Unit4Barcode];

        return array_values(array_filter(array_map(
            fn (ProductField $f) => trim((string) ($values[$f->value] ?? '')),
            $fields,
        )));
    }

    public static function barcodeTakenMessage(string $code): string
    {
        return sprintf('Barcode "%s" is already used by another product in this shop, so it was skipped.', $code);
    }

    /**
     * @param  Collection<int, ParsedRow>  $rows
     * @return array{total: int, create: int, update: int, skip: int}
     */
    public function summarise(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'create' => $rows->where(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_CREATE)->count(),
            'update' => $rows->where(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_UPDATE)->count(),
            'skip' => $rows->where(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_SKIP)->count(),
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

    /**
     * Loose enough to pass a genuine rename or a typo fix, strict enough to
     * catch two unrelated products that merely happen to share an identifier.
     * "iPhone 13 Case Clear" vs "iPhone 13 Case (Clear)" should pass; "A1481"
     * vs "P204 20000MAH" — the real Zelink Tech collision — should not.
     */
    private function namesLookRelated(string $a, string $b): bool
    {
        $normalize = fn (string $s) => strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $s));

        $na = $normalize($a);
        $nb = $normalize($b);

        if ($na === '' || $nb === '') {
            return true;
        }

        if ($na === $nb || str_contains($na, $nb) || str_contains($nb, $na)) {
            return true;
        }

        similar_text($na, $nb, $percent);

        return $percent >= 40.0;
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
