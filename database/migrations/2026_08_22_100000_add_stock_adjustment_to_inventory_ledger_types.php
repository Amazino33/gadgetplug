<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Records a manual correction to stock on hand — most often a vendor's opening
// balance, typed from the sheet they arrived with.
//
// Products import at zero on purpose (see ProductField::Quantity), and until now
// the only ways in were a procurement or a full count. Neither fits a vendor
// onboarding a catalogue they already know the numbers for, so this gives that
// movement a type of its own rather than dressing it up as a 'restock' (which
// implies a supplier and a cost) or an 'audit_correction' (which implies a count
// that never happened).
//
// MySQL only, and per the note on the store_transfer migration: the SQLite test
// suite cannot catch a value missing from this enum, so a type that skipped this
// file would pass every test and fail in production under strict mode. Verified
// by running it against the MySQL development database and writing a real
// stock_adjustment row.
return new class extends Migration
{
    private const TYPES_AFTER = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer','stock_adjustment'";

    private const TYPES_BEFORE = "'online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return','store_transfer'";

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
