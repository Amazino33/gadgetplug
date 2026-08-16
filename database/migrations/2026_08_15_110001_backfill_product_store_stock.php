<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Moves every product's existing stock onto a per-store row at its vendor's
// default store. The product columns are NOT touched or dropped — they remain
// the live source of truth for every mutator and reader. After this runs the
// two representations hold identical numbers, asserted three ways below.
//
// If any assertion fails the migration throws and the transaction-less writes
// it already made are left visible on purpose: a half-populated table you can
// inspect beats a silent rollback that hides which product disagreed.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // A vendor created after Phase 1's backfill but before this phase's
        // VendorObserver hook shipped has no default store, and its products
        // would have nowhere to land. Closing that here rather than failing:
        // the assertions below are meant to catch data corruption, not a known
        // and trivially fixable gap in coverage. Idempotent, same as the
        // observer's own seeding.
        $this->ensureEveryVendorHasADefaultStore($now);

        $defaultStoreByVendor = DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id', 'vendor_id');

        DB::table('products')
            ->select('id', 'vendor_id', 'stock_quantity', 'reserved_stock')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($defaultStoreByVendor, $now) {
                $rows = [];

                foreach ($products as $product) {
                    $storeId = $defaultStoreByVendor[$product->vendor_id] ?? null;

                    if ($storeId === null) {
                        throw new RuntimeException(
                            "Product store stock backfill aborted: vendor {$product->vendor_id} has no default store (product {$product->id})."
                        );
                    }

                    $alreadyThere = DB::table('product_store_stock')
                        ->where('product_id', $product->id)
                        ->where('store_id', $storeId)
                        ->exists();

                    if ($alreadyThere) {
                        continue;
                    }

                    $rows[] = [
                        'product_id' => $product->id,
                        'store_id'   => $storeId,
                        'quantity'   => (int) $product->stock_quantity,
                        'reserved'   => (int) $product->reserved_stock,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('product_store_stock')->insert($rows);
                }
            });

        $this->assertIntegrity();
    }

    // The rows this wrote are dropped wholesale by the create-table migration
    // above it. Emptying the table here instead would throw away per-store
    // stock that a later phase may have moved by hand.
    public function down(): void
    {
        //
    }

    private function ensureEveryVendorHasADefaultStore($now): void
    {
        $vendorsWithDefault = DB::table('stores')->where('is_default', true)->pluck('vendor_id')->all();

        $missing = DB::table('vendors')->whereNotIn('id', $vendorsWithDefault ?: [0])->pluck('id');

        foreach ($missing as $vendorId) {
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

    // Three independent checks. Counts catch a product that never landed;
    // the two sums catch a product that landed with the wrong number — a
    // count-only check would pass happily while every quantity was zero.
    private function assertIntegrity(): void
    {
        $products = DB::table('products');
        $stock = DB::table('product_store_stock');

        $productCount = $products->count();
        $stockCount = $stock->count();

        if ($productCount !== $stockCount) {
            throw new RuntimeException(
                "Product store stock backfill aborted: {$productCount} products but {$stockCount} store-stock rows."
            );
        }

        $productQuantity = (int) DB::table('products')->sum('stock_quantity');
        $stockQuantity = (int) DB::table('product_store_stock')->sum('quantity');

        if ($productQuantity !== $stockQuantity) {
            throw new RuntimeException(
                "Product store stock backfill aborted: quantity total {$stockQuantity} does not match products total {$productQuantity}."
            );
        }

        $productReserved = (int) DB::table('products')->sum('reserved_stock');
        $stockReserved = (int) DB::table('product_store_stock')->sum('reserved');

        if ($productReserved !== $stockReserved) {
            throw new RuntimeException(
                "Product store stock backfill aborted: reserved total {$stockReserved} does not match products total {$productReserved}."
            );
        }
    }
};
