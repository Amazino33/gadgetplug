<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Selling units on the product itself, and a base unit for every product that
// already exists.
//
// measurement_unit is left exactly as it was — the importer and exporter still
// read and write it. base_unit starts from it where it was filled, so a vendor
// who already called their goods "bottle" sees "bottle" rather than "Piece".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('base_unit', 32)->default('Piece')->after('measurement_unit');
            $table->decimal('wholesale_price', 12, 2)->nullable()->after('price');
            $table->foreignId('default_unit_id')->nullable()->after('base_unit')
                ->constrained('product_units')->nullOnDelete();
            // Null = follow the shop's setting. True/false overrides it for
            // this one product.
            $table->boolean('negative_stock_override')->nullable()->after('default_unit_id');
        });

        DB::table('products')
            ->whereNotNull('measurement_unit')
            ->where('measurement_unit', '!=', '')
            ->update(['base_unit' => DB::raw('measurement_unit')]);

        // One base unit per existing product, at its current price, made the
        // default. Every product sells exactly as before: one unit, factor 1.
        DB::table('products')
            ->select(['id', 'vendor_id', 'price', 'base_unit'])
            ->orderBy('id')
            ->chunkById(500, function ($products) {
                $now = now();

                DB::table('product_units')->insert($products->map(fn ($p) => [
                    'product_id' => $p->id,
                    'vendor_id' => $p->vendor_id,
                    'name' => mb_substr($p->base_unit ?: 'Piece', 0, 64),
                    'conversion_factor' => 1,
                    'price' => $p->price ?? 0,
                    'wholesale_price' => null,
                    'is_base' => true,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                $unitIds = DB::table('product_units')
                    ->whereIn('product_id', $products->pluck('id'))
                    ->where('is_base', true)
                    ->pluck('id', 'product_id');

                foreach ($unitIds as $productId => $unitId) {
                    DB::table('products')->where('id', $productId)->update(['default_unit_id' => $unitId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_unit_id');
            $table->dropColumn(['base_unit', 'wholesale_price', 'negative_stock_override']);
        });

        // The base units this migration created. Extra units added later by
        // vendors are left for the product_units rollback to decide on.
        DB::table('product_units')->where('is_base', true)->delete();
    }
};
