<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The unit goods were bought in: "5 Cartons of 48 at ₦10,000 each".
//
// quantity and unit_cost stay in base units (240 pieces at ₦208.33), so
// approval, corrections and supplier payables are untouched. These columns
// record what was actually on the invoice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
            $table->unsignedInteger('unit_qty')->nullable()->after('quantity');
            $table->decimal('cost_per_unit', 12, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_qty', 'cost_per_unit']);
        });
    }
};
