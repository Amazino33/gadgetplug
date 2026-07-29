<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MODIFY COLUMN is MySQL-only syntax. SQLite (the test-suite driver) has
        // no equivalent and does not enforce ENUM, so there is nothing to widen.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','confirmed','paid','shipped','delivered','cancelled','paid_but_failed_stock') DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','paid','shipped','delivered','cancelled') DEFAULT 'pending'");
    }
};
