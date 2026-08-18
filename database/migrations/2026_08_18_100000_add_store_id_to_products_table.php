<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A product's home store — the one branch it belongs to.
//
// Until now a product could in principle hold stock in several stores; in
// practice every product already had exactly one row, because ProductObserver
// opens one at creation and nothing else ever added a second. This makes that
// existing shape explicit and queryable.
//
// A column rather than "whichever store_stock row exists": a stock row is a
// stock fact and may legitimately fall to zero or be removed, while home store
// is an identity fact that has to survive either. It also turns every catalog
// read — including the POS endpoint, the hottest one — into a single indexed
// column compare instead of a whereHas subquery.
//
// Nullable so the column can land before it is populated, then backfilled and
// asserted below. Nothing reads it yet.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'store_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            });
        }

        $this->backfillHomeStores();
        $this->assertEveryProductHasAHomeStoreInItsOwnVendor();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }

    // Home is wherever the product's stock already sits — that is where staff
    // will expect to find it. A product holding stock in more than one store
    // (only possible from a deliberate multi-store seed) goes to whichever
    // holds the most, ties broken by the default store then the lowest id, so
    // the outcome never depends on row order.
    private function backfillHomeStores(): void
    {
        $defaultStoreByVendor = DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id', 'vendor_id');

        DB::table('products')
            ->whereNull('store_id')
            ->select('id', 'vendor_id')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($defaultStoreByVendor) {
                foreach ($products as $product) {
                    $home = DB::table('product_store_stock as pss')
                        ->join('stores as s', 's.id', '=', 'pss.store_id')
                        ->where('pss.product_id', $product->id)
                        ->where('s.vendor_id', $product->vendor_id)
                        ->orderByDesc('pss.quantity')
                        ->orderByDesc('s.is_default')
                        ->orderBy('s.id')
                        ->value('pss.store_id')
                        ?? ($defaultStoreByVendor[$product->vendor_id] ?? null);

                    if ($home === null) {
                        throw new RuntimeException(
                            "Home store backfill aborted: vendor {$product->vendor_id} has no store for product {$product->id}."
                        );
                    }

                    DB::table('products')->where('id', $product->id)->update(['store_id' => $home]);
                }
            });
    }

    // Per product, and checking the store belongs to the same vendor: a home
    // store pointing into another vendor's business would be worse than none.
    private function assertEveryProductHasAHomeStoreInItsOwnVendor(): void
    {
        $orphans = DB::table('products as p')
            ->leftJoin('stores as s', 's.id', '=', 'p.store_id')
            ->whereNull('p.store_id')
            ->orWhereColumn('s.vendor_id', '!=', 'p.vendor_id')
            ->pluck('p.id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'Home store backfill aborted: products without a home store in their own vendor — ids '
                .$orphans->take(20)->implode(', ')
                .($orphans->count() > 20 ? ' (and '.($orphans->count() - 20).' more)' : '')
            );
        }
    }
};
