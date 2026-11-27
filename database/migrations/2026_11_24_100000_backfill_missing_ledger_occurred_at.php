<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Fills occurred_at on ledger rows that were written without it.
//
// The column arrived (2026_10_14_120100) on a branch that was not merged at
// the time, so a database that ran that migration early kept taking ledger
// rows from code that never set it. Same rules as the original backfill:
// created_at, or for a till sale the sale's own completed_at.
//
// Touches only rows where occurred_at is null, so it is a no-op wherever the
// column and its writers arrived together.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE inventory_ledgers
            SET occurred_at = COALESCE((
                SELECT pos_sales.completed_at FROM pos_sales
                WHERE inventory_ledgers.transaction_type = 'pos_sale'
                  AND pos_sales.reference = inventory_ledgers.reference
                  AND pos_sales.vendor_id = inventory_ledgers.vendor_id
            ), created_at)
            WHERE occurred_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // Nothing to undo: the rows had no value to go back to.
    }
};
