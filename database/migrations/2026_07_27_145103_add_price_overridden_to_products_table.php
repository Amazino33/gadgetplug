<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// True once a human has manually set the selling price away from the
// engine's suggestion — governs whether reconciliation may auto-adjust
// the live price (see the procurement pricing-engine workflow).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('price_overridden')->default(false)->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_overridden');
        });
    }
};
