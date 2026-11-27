<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The stock correction a count makes when it is approved.
//
// GadgetPlug: keeps online_return (2026_10_23_100001), so dated after it.
// ApproveStockCountAction has written 'count_adjustment' since settlement
// counts were introduced, but the value was never added here. Under MySQL
// strict mode the insert is refused, so approving any count that found a
// variance rolled back with a database error. The SQLite test suite cannot see
// it — the column there carries no CHECK constraint — which is why every test
// passed. Confirmed against the MySQL development database on 2026-10-14:
// SHOW COLUMNS listed every type below except this one.
//
// Batch stock counts post through the same type.
return new class extends Migration
{
    private const TYPES_AFTER = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment','picking_out','picking_return','online_return','count_adjustment'";

    private const TYPES_BEFORE = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment','picking_out','picking_return','online_return'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type ENUM('.self::TYPES_AFTER.') NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Rows already written under the new type would violate the narrowed
        // column. 'stock_adjustment' is the nearest honest description.
        DB::table('inventory_ledgers')
            ->where('transaction_type', 'count_adjustment')
            ->update(['transaction_type' => 'stock_adjustment']);

        DB::statement('ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type ENUM('.self::TYPES_BEFORE.') NOT NULL');
    }
};
