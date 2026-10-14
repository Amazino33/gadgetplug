<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A code for each selling unit, so "ALC-CASTLI-0042-CR12" at the till or in a
// search opens the crate rather than the single bottle.
//
// Nullable and unique per shop, the same rule as products.sku and
// product_barcodes.barcode: NULL is untouched by the index, so base unit rows
// (which answer to the product's own SKU) simply leave it empty.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->string('sku', 100)->nullable()->after('name');
            $table->unique(['vendor_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'sku']);
            $table->dropColumn('sku');
        });
    }
};
