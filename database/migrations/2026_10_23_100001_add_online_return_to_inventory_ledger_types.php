<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Goods from a failed online delivery, back on the shelf of the branch that
// handed them over.
//
// MySQL only, per the note on the stock_adjustment migration: SQLite has no
// ENUM, so the test suite cannot catch a type missing from this list. Verified
// by running it against the local MySQL database before commit.
return new class extends Migration
{
    private const TYPES_AFTER = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment','picking_out','picking_return','online_return'";

    private const TYPES_BEFORE = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment','picking_out','picking_return'";

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

        DB::statement('ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type ENUM('.self::TYPES_BEFORE.') NOT NULL');
    }
};
