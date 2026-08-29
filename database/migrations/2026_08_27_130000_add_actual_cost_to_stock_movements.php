<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a sold line actually cost, as opposed to what it was assumed to cost.
//
// Until now a sale snapshotted the product's single cost_price at the moment it
// rang up, so cost of goods sold was only ever as accurate as that one figure.
// With batches recorded (stock_cost_layers), a sale can instead be costed at
// what the units it consumed genuinely cost — 10 from a N1,000 delivery and 2
// from a N1,500 one is N13,000, not 12 x whatever the product happens to cost
// today.
//
// A total rather than a per-unit figure, because a single line can draw from
// several batches at different prices. Dividing that back into a unit cost
// would only reintroduce rounding into the one number that has to reconcile.
//
// All three columns are nullable, and every reader falls back to the old
// quantity x unit_cost arithmetic when the column is null. Lines sold before
// this migration therefore keep reporting exactly what they always did — no
// backfill can invent batch history that was never recorded.
return new class extends Migration
{
    public function up(): void
    {
        // What the units in this movement cost. Written for movements OUT;
        // null for receipts, which have no cost of sale.
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->decimal('cost_total', 14, 2)->nullable()->after('quantity_change');
        });

        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->decimal('cost_total', 14, 2)->nullable()->after('unit_cost');
        });

        // Set when the goods are dispatched, not when the order is placed —
        // that is when the units leave a specific branch's shelf and so when
        // it is known which batches they came from.
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('cost_total', 14, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->dropColumn('cost_total');
        });

        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->dropColumn('cost_total');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('cost_total');
        });
    }
};
