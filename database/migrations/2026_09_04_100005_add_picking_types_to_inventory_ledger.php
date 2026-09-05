<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Goods leaving the shelf with a picker, and coming back unsold.
//
// A picking is not a sale: the goods are still the vendor's until paid for, and
// can be asked back. But the units genuinely leave the branch, so the movement
// belongs in the stock history like every other one — not in a side channel
// that stock_quantity would then have to be reconciled against.
//
// No type for a write-off: the units left the shelf at picking_out and never
// come back, so nothing moves when the owner gives up on the money. The loss is
// recorded on the picking ledger, where the value of it lives.
//
// MySQL only, and per the note on the stock_adjustment migration: the SQLite
// test suite cannot catch a value missing from this enum, so a type that
// skipped this file would pass every test and fail in production under strict
// mode.
//
// NOT YET VERIFIED AGAINST MySQL. The earlier migrations of this shape each
// carry a line saying they were run against the MySQL development database and
// a real row written; that could not be done here because the local MySQL was
// not running when this was written. Before this ships, run it against MySQL
// and write a picking_out and a picking_return row, then replace this note.
return new class extends Migration
{
    private const TYPES_AFTER = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment','picking_out','picking_return'";

    private const TYPES_BEFORE = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment'";

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
