<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Any number of barcodes per product, and per selling unit.
//
// A crate carries a different barcode from the bottles inside it, and the same
// bottle can arrive with two manufacturers' codes. products.barcode keeps
// working exactly as before; it is mirrored in here as the base unit's barcode
// (product_unit_id null) so a scan only ever has one table to look in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Null = the base unit.
            $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->cascadeOnDelete();
            $table->string('barcode', 100);
            $table->timestamps();

            // Same rule as products.barcode: one shop, one owner per code, or a
            // scan could not know which product the customer is holding.
            $table->unique(['vendor_id', 'barcode']);
            $table->index('product_id');
        });

        DB::table('products')
            ->select(['id', 'vendor_id', 'barcode'])
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($products) {
                $now = now();

                DB::table('product_barcodes')->insert($products->map(fn ($p) => [
                    'vendor_id'       => $p->vendor_id,
                    'product_id'      => $p->id,
                    'product_unit_id' => null,
                    'barcode'         => $p->barcode,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
