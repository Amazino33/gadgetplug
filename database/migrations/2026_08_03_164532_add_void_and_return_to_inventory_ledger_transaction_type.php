<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MODIFY COLUMN is MySQL-only syntax; SQLite (the test-suite driver) has
        // no equivalent and does not enforce ENUM, so there is nothing to widen.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // PosSaleController::void()/processReturn() already write 'pos_void' and
        // 'pos_return' via AdjustStockAction — those values were never added to
        // this enum, so voiding a sale or processing a return has been throwing
        // a DB error on MySQL (strict mode) and rolling back the whole action.
        DB::statement("ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type
            ENUM('online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released','pos_void','pos_return')
            NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type
            ENUM('online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released')
            NOT NULL");
    }
};
