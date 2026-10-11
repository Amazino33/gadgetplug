<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether cashiers may change a unit price at the Sales Point.
//
// Off by default: the till sells at the catalogue price (retail or wholesale)
// and the server ignores any other price a till sends. On, the price is
// click-to-edit as before, still never below the product's floor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('pos_allow_price_change')->default(false)->after('pos_ask_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('pos_allow_price_change');
        });
    }
};
