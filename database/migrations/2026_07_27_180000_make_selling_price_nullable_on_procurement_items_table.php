<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The single (auto-pricing) create form never populates selling_price —
// suggested_price (from Batch 1) is its replacement for new procurements.
// Existing rows from the old wizard flow keep their real values; this only
// stops NOT NULL from rejecting new inserts that legitimately have none.
// Raw MODIFY (not a portable ->change(), doctrine/dbal isn't installed —
// see 2026_07_27_150000's note on this repo's existing enum-migration
// pattern) — MySQL-only, same known SQLite-testing limitation as before.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE procurement_items MODIFY COLUMN selling_price DECIMAL(12,2) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE procurement_items SET selling_price = 0 WHERE selling_price IS NULL');
        DB::statement('ALTER TABLE procurement_items MODIFY COLUMN selling_price DECIMAL(12,2) NOT NULL');
    }
};
