<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What the customer was actually sold: "1 Crate at ₦9,700".
//
// quantity, unit_price and total keep their meaning — quantity is still in base
// units (24 bottles), unit_price is the per-bottle equivalent and total is the
// line's money — so stock, cost and every existing report read a unit sale the
// same way they read any other. These columns only record how it was sold.
//
// sold_unit_price is the price per unit sold (₦9,700 per crate). It is not
// named unit_price because that column already exists and means per base unit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_sku')
                ->constrained('product_units')->nullOnDelete();
            // Snapshots, so a unit renamed or deleted later does not rewrite
            // what an old receipt says.
            $table->string('unit_name', 64)->nullable()->after('product_unit_id');
            $table->unsignedInteger('unit_qty')->nullable()->after('unit_name');
            $table->decimal('sold_unit_price', 12, 2)->nullable()->after('unit_qty');
            $table->string('price_type', 16)->default('retail')->after('sold_unit_price');
            // The shop sold more than it had on record, so there were no
            // purchase batches to cost these units from and the product's
            // current cost price was used instead.
            $table->boolean('cost_estimated')->default(false)->after('cost_total');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'unit_qty', 'sold_unit_price', 'price_type', 'cost_estimated']);
        });
    }
};
