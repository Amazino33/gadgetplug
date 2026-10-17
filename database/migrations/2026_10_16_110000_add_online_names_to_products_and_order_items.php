<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Online names.
//
// products.online_name is the search-friendly name customers see ("itel 2160
// Dual SIM Keypad Phone – Black"); products.name stays the store's internal
// name for POS, receipts, stock work and storekeeper messages. Both nullable:
// no online name means customers see the internal name, as before.
//
// order_items gains a snapshot of both names, taken when the order is placed,
// so renaming a product later never rewrites what an existing order says.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('online_name', 150)->nullable()->after('name');
            $table->string('meta_description', 300)->nullable()->after('description');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('product_display_name')->nullable()->after('product_name');
        });

        // Backfill existing lines — BEST AVAILABLE, not historical truth: there
        // was no snapshot before this, so lines take the product's names as they
        // are today. No online names exist yet, so the display name is what a
        // customer would see right now: a resold listing's supplier product
        // name, otherwise the product's own name. Lines whose product has been
        // deleted stay null and fall back to "Item" where they are read.
        //
        // Plain query builder in chunks so it runs the same on MySQL and on the
        // SQLite test database, with no raw DDL.
        DB::table('order_items')
            ->whereNull('product_name')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                $products = DB::table('products')
                    ->whereIn('id', $items->pluck('product_id')->unique())
                    ->get(['id', 'name', 'online_name', 'source_product_id'])
                    ->keyBy('id');

                $sources = DB::table('products')
                    ->whereIn('id', $products->pluck('source_product_id')->filter()->unique())
                    ->get(['id', 'name', 'online_name'])
                    ->keyBy('id');

                foreach ($items as $item) {
                    $product = $products->get($item->product_id);

                    if (! $product) {
                        continue;
                    }

                    $shown = $product->source_product_id ? ($sources->get($product->source_product_id) ?? $product) : $product;

                    DB::table('order_items')->where('id', $item->id)->update([
                        'product_name'         => $product->name,
                        'product_display_name' => $shown->online_name ?: $shown->name,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'product_display_name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['online_name', 'meta_description']);
        });
    }
};
