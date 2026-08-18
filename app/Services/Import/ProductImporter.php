<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Category;
use App\Models\ImportLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Vendor;
use App\Services\Export\ProductExporter;
use App\Support\Import\ProductField;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    ) {
    }

    /**
     * @param  Collection<int, ParsedRow>  $rows
     * @param  Closure(int, int): void|null  $onProgress  (processed, total)
     */
    public function commit(
        Collection $rows,
        Vendor $vendor,
        ?int $userId,
        string $fileName,
        ?Closure $onProgress = null,
    ): ImportLog {
        $importable = $rows->filter(fn (ParsedRow $row) => $row->isImportable())->values();
        $skipped    = $rows->reject(fn (ParsedRow $row) => $row->isImportable())->values();

        $log = ImportLog::create([
            'vendor_id'     => $vendor->id,
            'user_id'       => $userId,
            'file_name'     => $fileName,
            'total_rows'    => $rows->count(),
            'skipped_count' => $skipped->count(),
            'status'        => 'running',
            'errors'        => $this->errorPayload($skipped),
        ]);

        // Only when something existing is about to change. A first import that
        // creates everything has nothing to restore to, and writing a snapshot
        // of an empty catalogue would just be noise.
        if ($importable->contains(fn (ParsedRow $row) => $row->action() === ParsedRow::ACTION_UPDATE)) {
            $log->update(['snapshot_path' => $this->snapshot($vendor)]);
        }

        $created = 0;
        $updated = 0;
        $done    = 0;

        try {
            DB::transaction(function () use ($importable, $vendor, &$created, &$updated, &$done, $onProgress) {
                // Resolved once for the whole run: a file with 500 rows across
                // 12 categories should create 12 categories, not ask 500 times.
                $categories = new NameResolver(
                    fn (string $name) => $this->resolveCategory($name),
                );

                $suppliers = new NameResolver(
                    fn (string $name) => $this->resolveSupplier($name, $vendor),
                );

                foreach ($importable->chunk(self::CHUNK) as $chunk) {
                    foreach ($chunk as $row) {
                        $attributes = $this->attributesFor($row, $categories, $suppliers);

                        if ($row->action() === ParsedRow::ACTION_UPDATE) {
                            $product = Product::where('vendor_id', $vendor->id)
                                ->whereKey($row->matchedProductId)
                                ->first();

                            // Vanished between preview and confirm. Creating it
                            // instead would be a reasonable guess, but the
                            // vendor was shown "update" and is owed that.
                            if ($product === null) {
                                continue;
                            }

                            $product->fill($attributes)->save();
                            $updated++;
                        } else {
                            Product::create([
                                'vendor_id' => $vendor->id,
                                ...$this->defaultsForNewProduct(),
                                ...$attributes,
                            ]);
                            $created++;
                        }

                        $done++;
                    }

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
            'status'        => 'completed',
            'created_count' => $created,
            'updated_count' => $updated,
        ]);

        return $log->refresh();
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
            'status'      => 'draft',
            'show_online' => true,
            'show_in_pos' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function attributesFor(ParsedRow $row, NameResolver $categories, NameResolver $suppliers): array
    {
        $attributes = [];

        foreach ($row->values as $field => $value) {
            $attributes[match ($field) {
                ProductField::Category->value => 'category_id',
                ProductField::Supplier->value => 'supplier_id',
                default                       => $field,
            }] = match ($field) {
                ProductField::Category->value => $categories->resolve((string) $value),
                ProductField::Supplier->value => $suppliers->resolve((string) $value),
                default                       => $value,
            };
        }

        // A category is not optional on this table, so a row that named none
        // falls back rather than failing. Only ever applied to new products -
        // an update with no category column must leave the existing one alone.
        if (! isset($attributes['category_id']) && $row->action() === ParsedRow::ACTION_CREATE) {
            $attributes['category_id'] = $categories->resolve('Uncategorised');
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
            'name'      => $name,
            'slug'      => $this->uniqueCategorySlug($name),
            'is_active' => true,
        ])->id;
    }

    /** categories.slug is globally unique, so a clash has to be resolved rather than thrown. */
    private function uniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $n    = 2;

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
            'name'      => $name,
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
     * @param  Collection<int, ParsedRow>  $skipped
     * @return array<int, array{line: int, name: string, errors: array<int, string>}>
     */
    private function errorPayload(Collection $skipped): array
    {
        return $skipped
            // Bounded: a vendor who maps the wrong column can produce thousands
            // of identical errors, and storing them all would bloat the row for
            // no extra insight.
            ->take(200)
            ->map(fn (ParsedRow $row) => [
                'line'   => $row->line,
                'name'   => $row->name(),
                'errors' => $row->errors,
            ])
            ->values()
            ->all();
    }
}
