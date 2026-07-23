<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Raw SQL — doctrine/dbal isn't installed, so column::change() isn't available.
    // Existing rows keep their current value (0 stays 0); only newly-blank entries
    // going forward will actually be NULL.
    public function up(): void
    {
        DB::statement('ALTER TABLE products MODIFY cost_price DECIMAL(12,2) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE products SET cost_price = 0 WHERE cost_price IS NULL");
        DB::statement('ALTER TABLE products MODIFY cost_price DECIMAL(12,2) NOT NULL DEFAULT 0');
    }
};
