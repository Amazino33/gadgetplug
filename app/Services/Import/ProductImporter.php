<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Category;
use App\Models\ImportLog;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Vendor;
use App\Services\Export\ProductExporter;
use App\Support\Import\ProductField;
use App\Support\Quantity;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Writes prepared rows to the catalogue.
 *
 * Two guarantees, both of which cost something and are worth it:
 *
 * 1. All or nothing. The whole run is one transaction, so a file that fails on
 *    row 400 leaves the catalogue exactly as it was rather than half-rewritten
 *    with no record of where it stopped.
 * 2. Recoverable. The vendor's current catalogue is exported to disk before any
 *    row that updates an existing product is applied. A vendor who imports last
 *    year's price list has something to restore from; a rollback the database
 *    cannot give them once the transaction commits.
 */
class ProductImporter
{
    /** Rows per insert batch. Large enough to be cheap, small enough to report progress. */
    public const CHUNK = 200;

    public function __construct(
        private readonly ProductExporter $exporter,
    ) {}

    /**
     * @param  Collection<int, ParsedRow>  $rows
     * @param  Closure(int, int): void|null  $onProgress  (processed, total)
     * @param  bool  $performedByAdmin  platform staff ran this for the vendor
     * @param  int|null  $storeId  the branch new products are homed in
     */
    public function commit(
        Collection $rows,
        Vendor $vendor,
        ?int $userId,
        string $fileName,
        ?Closure $onProgress = null,
        bool $performedByAdmin = false,
        ?int $storeId = null,
    ): ImportLog {
        // Opening stock in halves only for a shop that sells halves. Checked
        // here, where the shop is known, so the row is skipped with a reason
        // rather than its half quietly dropped.
        $rows = $rows->map(function (ParsedRow $row) use ($vendor) {
            $quantity = $row->value(ProductField::Quantity->value);

            if ($quantity === null || Quantity::isWhole($quantity)) {
                return $row;
            }

            return Quantity::allowsHalves($vendor) && Quantity::isHalfStep($quantity)
                ? $row
                : $row->withError(Quantity::allowsHalves($vendor)
                    ? 'Quantity goes in halves: ½, 1, 1½ …'
                    : 'Quantity must be a whole number.');
        });

        $importable = $rows->filter(fn (ParsedRow $row) => $row->isImportable())->values();
        $skipped = $rows->reject(fn (ParsedRow $row) => $row->isImportable())->values();

        $log = ImportLog::create([
            'vendor_id' => $vendor->id,
            'user_id' => $userId,
            // Carried by the caller rather than inferred here: this service is
            // driven by the panel, the console and tests alike, and only the
            // caller knows whose hand was on it.
            'performed_by_admin' => $performedByAdmin,
            'store_id' => $storeId,
            'file_name' => $fileName,
            'total_rows' => $rows->count(),
            'skipped_count' => $skipped->count(),
            'status' => 'running',
            'errors' => $this->errorPayload($skipped),
        ]);

        // Only when something existing is about to change. A first import that
        // creates everything has nothing to restore to, and writing a snapshot
        // of an empty catalogue would just be noise.
        if ($importable->contains(fn (ParsedRow $row) => $row->action() === ParsedRow::ACTION_UPDATE)) {
            $log->update(['snapshot_path' => $this->snapshot($vendor)]);
        }

        $created = 0;
        $updated = 0;
        $done = 0;
        $extra = ['units_created' => 0, 'units_updated' => 0, 'barcodes_added' => 0, 'opening_stock' => 0];
        $warnings = $this->warningPayload($importable);

        try {
            DB::transaction(function () use ($importable, $vendor, $userId, &$created, &$updated, &$done, &$extra, &$warnings, $onProgress) {
                foreach ($importable->chunk(self::CHUNK) as $chunk) {
                    $counts = $this->applyChunk($chunk, $vendor, $userId);

                    // This path promises all or nothing, so a row that could
                    // not be written stops the run and undoes the rest.
                    if ($counts['failures'] !== []) {
                        $first = $counts['failures'][0];

                        throw new RuntimeException(sprintf('Line %d (%s): %s', $first['line'], $first['name'], implode(' ', $first['errors'])));
                    }

                    $created += $counts['created'];
                    $updated += $counts['updated'];
                    $done += $chunk->count();

                    foreach (array_keys($extra) as $key) {
                        $extra[$key] += $counts[$key];
                    }

                    $warnings = self::mergeWarnings($warnings, $counts['warnings']);

                    if ($onProgress !== null) {
                        $onProgress($done, $importable->count());
                    }
                }
            });
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'errors' => [
                    ...$this->errorPayload($skipped),
                    ['line' => null, 'name' => null, 'errors' => ['The import was rolled back: '.$e->getMessage()]],
                ],
            ]);

            throw $e;
        }

        $log->update([
            'status' => 'completed',
            'created_count' => $created,
            'updated_count' => $updated,
            'units_created_count' => $extra['units_created'],
            'units_updated_count' => $extra['units_updated'],
            'barcodes_added_count' => $extra['barcodes_added'],
            'opening_stock_count' => $extra['opening_stock'],
            'warnings' => $warnings,
        ]);

        return $log->refresh();
    }

    /**
     * Write one batch of rows, in its own transaction.
     *
     * Public because a large file cannot be committed in a single web request:
     * every product costs an insert, a slug uniqueness check, a store stock row,
     * a mirror recompute and an activity log entry, so six hundred products is
     * several thousand queries. Shared hosting kills that long before it
     * finishes, and typically with set_time_limit disabled, so the request dies
     * silently and the vendor sees nothing happen at all. The page drives this
     * a batch per request instead.
     *
     * Nested inside commit()'s transaction this becomes a savepoint, so the
     * whole-file guarantee still holds for callers that can afford one request.
     *
     * @param  Collection<int, ParsedRow>  $rows
     * @return array{created: int, updated: int}
     */
    public function applyChunk(Collection $rows, Vendor $vendor, ?int $userId = null): array
    {
        return DB::transaction(function () use ($rows, $vendor, $userId) {
            $totals = [
                'created' => 0,
                'updated' => 0,
                'units_created' => 0,
                'units_updated' => 0,
                'barcodes_added' => 0,
                'opening_stock' => 0,
                // [{line, name, message}] — the row went in, minus what is named.
                'warnings' => [],
                // [{line, name, errors: [..]}] — the row did not go in at all.
                'failures' => [],
            ];

            // Resolved once per batch: a file naming 12 categories should ask
            // about 12, not once per row.
            $categories = new NameResolver(fn (string $name) => $this->resolveCategory($name));
            $suppliers = new NameResolver(fn (string $name) => $this->resolveSupplier($name, $vendor));

            foreach ($rows as $row) {
                // Each row in its own savepoint. One bad row used to take its
                // whole batch down with a message naming none of them; now it
                // is undone on its own, named by line, and the rest go in.
                try {
                    $counts = DB::transaction(fn () => $this->applyRow($row, $vendor, $userId, $categories, $suppliers));
                } catch (Throwable $e) {
                    $totals['failures'][] = [
                        'line' => $row->line,
                        'name' => $row->name(),
                        'errors' => [$this->reasonFor($e)],
                    ];

                    continue;
                }

                foreach (['created', 'updated', 'units_created', 'units_updated', 'barcodes_added', 'opening_stock'] as $key) {
                    $totals[$key] += $counts[$key];
                }

                foreach ($counts['warnings'] as $message) {
                    $totals['warnings'][] = ['line' => $row->line, 'name' => $row->name(), 'message' => $message];
                }
            }

            return $totals;
        });
    }

    /**
     * Whoever is running this import may set online names: super_admin or a
     * holder of update_product_online_name (the same check the admin action
     * uses, through the policy). No signed-in user — e.g. an artisan import —
     * may not.
     */
    private function mayWriteOnlineNames(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->isSuperAdmin() || $user->hasGlobalPermission('update_product_online_name'));
    }

    /**
     * Writes one row: the product, its opening stock (new products only), its
     * extra units and its extra barcodes.
     *
     * @return array{created: int, updated: int, units_created: int, units_updated: int, barcodes_added: int, opening_stock: int, warnings: array<int, string>}
     */
    private function applyRow(ParsedRow $row, Vendor $vendor, ?int $userId, NameResolver $categories, NameResolver $suppliers): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'units_created' => 0, 'units_updated' => 0, 'barcodes_added' => 0, 'opening_stock' => 0, 'warnings' => []];
        $attributes = $this->attributesFor($row, $categories, $suppliers);

        if ($row->action() === ParsedRow::ACTION_UPDATE) {
            $product = Product::where('vendor_id', $vendor->id)
                ->whereKey($row->matchedProductId)
                ->first();

            // Vanished between preview and confirm. Creating it instead
            // would be a reasonable guess, but the vendor was shown
            // "update" and is owed that.
            if ($product === null) {
                return $counts;
            }

            $barcodesBefore = ProductBarcode::where('product_id', $product->id)->count();

            $product->fill($attributes)->save();
            $counts['updated'] = 1;
        } else {
            $barcodesBefore = 0;

            $product = Product::create([
                'vendor_id' => $vendor->id,
                ...$this->defaultsForNewProduct(),
                ...$attributes,
            ]);
            $counts['created'] = 1;

            // Opening stock, for a new product only, through the same ledgered
            // movement every other stock change uses. Below zero is allowed
            // here whatever the shop's setting: it records goods already sold.
            $quantity = $row->value(ProductField::Quantity->value);

            if ($quantity !== null && Quantity::of($quantity) !== 0 && ! $product->is_service) {
                app(AdjustStockAction::class)->execute(
                    productId: $product->id,
                    quantityChanged: Quantity::of($quantity),
                    transactionType: 'stock_adjustment',
                    userId: $userId,
                    reference: 'IMPORT',
                    description: 'Opening stock — import',
                    reasonCode: 'opening_stock',
                    allowNegative: true,
                );
                $counts['opening_stock'] = 1;
            }
        }

        // Extra units, matched on product + name so a second import updates
        // rather than duplicates. Units not in the file are left alone.
        [$units] = ImportPreparer::unitsFor($row->values);

        foreach ($units as $u) {
            $unit = ProductUnit::where('product_id', $product->id)
                ->where('is_base', false)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($u['name'])])
                ->first();

            $values = ['name' => $u['name'], 'conversion_factor' => $u['contains']];

            if ($u['wholesale'] !== null) {
                $values['wholesale_price'] = $u['wholesale'];
            }

            // A blank price leaves an existing unit's price as it was; a new
            // unit with none, or any unit priced 0, is saved switched off.
            if ($u['price'] !== null) {
                $values['price'] = $u['price'];
                $values['is_active'] = $u['active'];
            }

            if ($unit) {
                $unit->fill($values);

                if ($unit->isDirty()) {
                    $unit->save();
                    $counts['units_updated']++;
                }
            } else {
                $unit = ProductUnit::create($values + [
                    'product_id' => $product->id,
                    'vendor_id' => $vendor->id,
                    'price' => 0,
                    'is_active' => false,
                    'sort_order' => $u['slot'],
                ]);
                $counts['units_created']++;
            }

            if ($u['barcode'] !== '') {
                $this->addBarcode($product, $unit->id, $u['barcode'], $counts['warnings']);
            }
        }

        $alt = trim((string) ($row->value(ProductField::AltBarcode->value) ?? ''));

        if ($alt !== '') {
            $this->addBarcode($product, null, $alt, $counts['warnings']);
        }

        // Main barcode (mirrored by the model) and the extra ones, counted as
        // whatever this row actually added.
        $counts['barcodes_added'] = max(0, ProductBarcode::where('product_id', $product->id)->count() - $barcodesBefore);

        return $counts;
    }

    /**
     * Gives a product (or one of its units) another barcode, unless some other
     * product in the shop already answers to it — then it is skipped and the
     * row says so. A code the product already carries is left where it is.
     *
     * @param  array<int, string>  $warnings
     */
    private function addBarcode(Product $product, ?int $unitId, string $code, array &$warnings): void
    {
        $existing = ProductBarcode::where('vendor_id', $product->vendor_id)->where('barcode', $code)->first();

        if ($existing !== null) {
            if ((int) $existing->product_id !== (int) $product->id) {
                $warnings[] = ImportPreparer::barcodeTakenMessage($code);
            }

            return;
        }

        $mainElsewhere = Product::where('vendor_id', $product->vendor_id)
            ->where('barcode', $code)
            ->whereKeyNot($product->id)
            ->exists();

        if ($mainElsewhere) {
            $warnings[] = ImportPreparer::barcodeTakenMessage($code);

            return;
        }

        ProductBarcode::create([
            'vendor_id' => $product->vendor_id,
            'product_id' => $product->id,
            'product_unit_id' => $unitId,
            'barcode' => $code,
        ]);
    }

    /** A row's failure in words the vendor can act on. */
    private function reasonFor(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', $e->validator->errors()->all());
        }

        return 'Could not be saved: '.$e->getMessage();
    }

    /** The pre-import safety copy, for callers driving the run themselves. */
    public function snapshotFor(Vendor $vendor): ?string
    {
        return $this->snapshot($vendor);
    }

    /**
     * Columns a brand-new product needs that no spreadsheet supplies.
     *
     * Stock is absent on purpose - it is a projection of the per-store rows and
     * moves only through AdjustStockAction, so a new product starts at zero and
     * the vendor's first count or procurement gives it a ledgered opening
     * balance.
     */
    private function defaultsForNewProduct(): array
    {
        return [
            'status' => 'draft',
            'show_online' => true,
            'show_in_pos' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function attributesFor(ParsedRow $row, NameResolver $categories, NameResolver $suppliers): array
    {
        $attributes = [];

        foreach ($row->values as $field => $value) {
            // Units, extra barcodes and opening stock are written separately;
            // only real product columns go onto the row.
            if (ProductField::tryFrom($field)?->isProductColumn() === false) {
                continue;
            }

            $attributes[match ($field) {
                ProductField::Category->value => 'category_id',
                ProductField::Supplier->value => 'supplier_id',
                default => $field,
            }] = match ($field) {
                ProductField::Category->value => $categories->resolve((string) $value),
                ProductField::Supplier->value => $suppliers->resolve((string) $value),
                default => $value,
            };
        }

        // Online name and meta description are GadgetPlug's, not the vendor's.
        // A vendor round-tripping an export carries them back unchanged; only
        // someone allowed to edit online names may actually write them.
        if (! $this->mayWriteOnlineNames()) {
            unset($attributes[ProductField::OnlineName->value], $attributes[ProductField::MetaDescription->value]);
        }

        // A category is not optional on this table, so a row that named none
        // falls back rather than failing. Only ever applied to new products -
        // an update with no category column must leave the existing one alone.
        if (! isset($attributes['category_id']) && $row->action() === ParsedRow::ACTION_CREATE) {
            $attributes['category_id'] = $categories->resolve('Uncategorised');
        }

        // "Unit" is the base unit: stock and Quantity are counted in it, and
        // it names the base selling unit at the till.
        //
        // The column may be headed "Unit", "Base Unit" or "Measurement Unit" -
        // all map to this one field (see ProductField::aliases). base_unit is
        // the source of truth; measurement_unit is still written, mirroring it,
        // because older exports and anything reading that column expect it.
        if (filled($attributes['measurement_unit'] ?? null)) {
            $attributes['base_unit'] = mb_substr((string) $attributes['measurement_unit'], 0, 32);
            $attributes['measurement_unit'] = $attributes['base_unit'];
        }

        return $attributes;
    }

    /**
     * Categories are shared across every vendor on the platform, so this matches
     * an existing one case-insensitively before creating anything - otherwise
     * "Phones", "phones" and "PHONES" would become three entries in a dropdown
     * every vendor sees.
     */
    private function resolveCategory(string $name): int
    {
        $name = Str::squish($name);

        $existing = Category::whereRaw('LOWER(name) = ?', [Str::lower($name)])->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return Category::create([
            'name' => $name,
            'slug' => $this->uniqueCategorySlug($name),
            'is_active' => true,
        ])->id;
    }

    /** categories.slug is globally unique, so a clash has to be resolved rather than thrown. */
    private function uniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $n = 2;

        while (Category::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** Suppliers are vendor-scoped, so this cannot collide across tenants. */
    private function resolveSupplier(string $name, Vendor $vendor): int
    {
        $name = Str::squish($name);

        $existing = Supplier::where('vendor_id', $vendor->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return Supplier::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
        ])->id;
    }

    private function snapshot(Vendor $vendor): ?string
    {
        try {
            return $this->exporter->snapshot($vendor);
        } catch (Throwable $e) {
            // A snapshot that cannot be written must not stop the import - it is
            // a safety net, and refusing to act because the net is missing helps
            // nobody. The log records that there is none.
            report($e);

            return null;
        }
    }

    /**
     * The preview's warnings for the rows that will be written, in the shape
     * the log stores: one entry per message.
     *
     * @param  Collection<int, ParsedRow>  $rows
     * @return array<int, array{line: int, name: string, message: string}>
     */
    public function warningPayload(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (ParsedRow $row) => array_map(
                fn (string $message) => ['line' => $row->line, 'name' => $row->name(), 'message' => $message],
                $row->warnings,
            ))
            ->values()
            ->all();
    }

    /**
     * Adds warnings found while writing to those found in the preview, once
     * each — a skipped barcode is spotted by both and said once.
     */
    public static function mergeWarnings(array $existing, array $new): array
    {
        $seen = [];

        foreach ($existing as $w) {
            $seen[$w['line'].'|'.$w['message']] = true;
        }

        foreach ($new as $w) {
            if (! isset($seen[$w['line'].'|'.$w['message']])) {
                $existing[] = $w;
                $seen[$w['line'].'|'.$w['message']] = true;
            }
        }

        return $existing;
    }

    /**
     * @param  Collection<int, ParsedRow>  $skipped
     * @return array<int, array{line: int, name: string, errors: array<int, string>}>
     */
    private function errorPayload(Collection $skipped): array
    {
        // Every one of them: the vendor downloads this list to fix their file,
        // and a list that stops at 200 leaves them guessing about the rest.
        return $skipped
            ->map(fn (ParsedRow $row) => [
                'line' => $row->line,
                'name' => $row->name(),
                'errors' => $row->errors,
            ])
            ->values()
            ->all();
    }
}
