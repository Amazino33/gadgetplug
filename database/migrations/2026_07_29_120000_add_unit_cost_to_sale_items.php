<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Captures what each item COST at the moment it sold, alongside what it sold
// for. Without this, profit can only be measured against a product's current
// cost_price, so every restock at a new cost silently rewrites past profit.
//
// Nullable on purpose: rows written before this migration genuinely have no
// recorded cost, and NULL says that honestly. Reporting falls back to the
// product's current cost for those and flags the figure as approximate —
// backfilling a guessed number would launder an estimate into a fact.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });

        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
