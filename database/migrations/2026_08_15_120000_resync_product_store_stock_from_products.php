<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The handover. Until this runs, products.stock_quantity / reserved_stock are
// the truth and product_store_stock is a snapshot taken at the Phase 2a
// backfill. Every movement since then landed on the product columns only, so
// the store rows are stale — on the dev database one product was already four
// units behind.
//
// From the migration after this one onwards the store rows are authoritative
// and the product columns become a derived mirror. So this is the last moment
// the columns can be read as truth, and it must overwrite (not skip) every
// default-store row, which is exactly what the Phase 2a backfill could not do:
// that one was insert-only by design, so re-running it repairs nothing.
//
// Query-builder writes throughout, like the 2a backfill: no model events, so
// ProductStoreStockObserver never fires mid-migration and cannot recompute a
// mirror from rows that are still being written.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $this->ensureEveryVendorHasADefaultStore($now);

        $defaultStoreByVendor = DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id', 'vendor_id');

        DB::table('products')
            ->select('id', 'vendor_id', 'stock_quantity', 'reserved_stock')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($defaultStoreByVendor, $now) {
                foreach ($products as $product) {
                    $storeId = $defaultStoreByVendor[$product->vendor_id] ?? null;

                    if ($storeId === null) {
                        throw new RuntimeException(
                            "Stock re-sync aborted: vendor {$product->vendor_id} has no default store (product {$product->id})."
                        );
                    }

                    // updateOrInsert, not insert-or-skip: a product that gained
                    // stock since the 2a snapshot has a row holding the wrong
                    // number, and leaving it would hand that wrong number to
                    // the mirror the first time the product next moves.
                    DB::table('product_store_stock')->updateOrInsert(
                        ['product_id' => $product->id, 'store_id' => $storeId],
                        [
                            'quantity'   => (int) $product->stock_quantity,
                            'reserved'   => (int) $product->reserved_stock,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }
            });

        $this->assertReconciled();
    }

    // No-op on purpose. Rolling back cannot restore the pre-sync numbers —
    // they were stale, which is the whole reason this ran — and the tables it
    // wrote into are dropped by the migrations that created them.
    public function down(): void
    {
        //
    }

    private function ensureEveryVendorHasADefaultStore($now): void
    {
        $withDefault = DB::table('stores')->where('is_default', true)->pluck('vendor_id')->all();

        foreach (DB::table('vendors')->whereNotIn('id', $withDefault ?: [0])->pluck('id') as $vendorId) {
            DB::table('stores')->insert([
                'vendor_id'  => $vendorId,
                'name'       => 'Main Store',
                'slug'       => $this->uniqueSlug($vendorId, 'Main Store'),
                'is_default' => true,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function uniqueSlug(int $vendorId, string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while (DB::table('stores')->where('vendor_id', $vendorId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    // Per product, not in aggregate: two products drifting in opposite
    // directions would cancel out in a global SUM and slip through.
    //
    // This also catches the one case updateOrInsert cannot handle on its own —
    // a product already holding stock at a second, non-default store. Writing
    // the whole product total onto the default row would then double-count it,
    // and the per-product check fails loudly instead of shipping the error.
    private function assertReconciled(): void
    {
        $drifted = DB::table('products')
            ->leftJoin('product_store_stock', 'product_store_stock.product_id', '=', 'products.id')
            ->groupBy('products.id', 'products.stock_quantity', 'products.reserved_stock')
            ->havingRaw('COALESCE(SUM(product_store_stock.quantity), 0) <> products.stock_quantity')
            ->orHavingRaw('COALESCE(SUM(product_store_stock.reserved), 0) <> products.reserved_stock')
            ->select('products.id')
            ->pluck('products.id');

        if ($drifted->isNotEmpty()) {
            throw new RuntimeException(
                'Stock re-sync aborted: per-store rows do not reconcile with the product columns for product ids '
                .$drifted->take(20)->implode(', ')
                .($drifted->count() > 20 ? ' (and '.($drifted->count() - 20).' more)' : '')
            );
        }
    }
};
