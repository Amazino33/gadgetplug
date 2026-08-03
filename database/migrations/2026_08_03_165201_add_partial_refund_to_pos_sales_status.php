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

        // PosSaleController::processReturn() sets status to 'partial_refund' when
        // only some items from a sale are returned — that value was never added
        // to this enum, so a partial return has been throwing a DB error on
        // MySQL (strict mode) and rolling back the whole return.
        DB::statement("ALTER TABLE pos_sales MODIFY COLUMN status
            ENUM('completed','voided','refunded','partial_refund')
            NOT NULL DEFAULT 'completed'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE pos_sales MODIFY COLUMN status
            ENUM('completed','voided','refunded')
            NOT NULL DEFAULT 'completed'");
    }
};
