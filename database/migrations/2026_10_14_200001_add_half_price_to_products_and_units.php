<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What half of a unit sells for, when it is not exactly half the price.
//
// Null means automatic: half the unit's retail price, rounded to kobo. Only a
// shop that sells halves ever sees or sets it. products.half_price is the base
// unit's, mirrored into its product_units row like price and wholesale_price.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('half_price', 12, 2)->nullable()->after('wholesale_price');
        });

        Schema::table('product_units', function (Blueprint $table) {
            $table->decimal('half_price', 12, 2)->nullable()->after('wholesale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('half_price');
        });

        Schema::table('product_units', function (Blueprint $table) {
            $table->dropColumn('half_price');
        });
    }
};
