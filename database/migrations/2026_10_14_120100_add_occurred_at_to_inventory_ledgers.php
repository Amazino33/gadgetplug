<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// When a stock movement actually happened, as opposed to when the server heard
// about it.
//
// The two are the same for almost everything. They differ for a sale rung on a
// till with no signal: the goods left the shelf at the till's time, but the
// ledger row is written whenever the till next syncs, sometimes hours later.
//
// A count taken while the shop keeps selling depends on telling those apart.
// Stock at the moment an item was counted is worked out as "stock now, minus
// every movement after that moment" — and an offline sale rung BEFORE the count
// but synced AFTER it must not be counted as after, or the shelf is charged for
// it twice.
//
// Backfilled from created_at, and for till sales from the sale's own
// completed_at, which is the till's time for an offline sale and the server's
// for an online one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('reason_code');
            $table->index(['store_id', 'product_id', 'occurred_at'], 'il_store_product_occurred_idx');
        });

        DB::table('inventory_ledgers')->update(['occurred_at' => DB::raw('created_at')]);

        // A correlated subquery rather than UPDATE … JOIN, which SQLite (the
        // test suite) does not accept. pos_sales.reference is unique.
        DB::statement(<<<'SQL'
            UPDATE inventory_ledgers
            SET occurred_at = (
                SELECT pos_sales.completed_at FROM pos_sales
                WHERE pos_sales.reference = inventory_ledgers.reference
                  AND pos_sales.vendor_id = inventory_ledgers.vendor_id
            )
            WHERE transaction_type = 'pos_sale'
              AND EXISTS (
                SELECT 1 FROM pos_sales
                WHERE pos_sales.reference = inventory_ledgers.reference
                  AND pos_sales.vendor_id = inventory_ledgers.vendor_id
                  AND pos_sales.completed_at IS NOT NULL
              )
        SQL);
    }

    public function down(): void
    {
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->dropIndex('il_store_product_occurred_idx');
            $table->dropColumn('occurred_at');
        });
    }
};
