<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only. `unit_cost` (existing column) is what the pricing engine
// treats as the line's purchase price — no duplicate column added.
// `landed_unit_cost`/`suggested_price` are engine output: provisional
// (logistics_cost null on the parent procurement) then final after
// reconciliation. Existing `selling_price` is left untouched — it stays
// the actual price a line sells at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->decimal('landed_unit_cost', 12, 2)->nullable()->after('unit_cost');
            $table->decimal('suggested_price', 12, 2)->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->dropColumn(['landed_unit_cost', 'suggested_price']);
        });
    }
};
