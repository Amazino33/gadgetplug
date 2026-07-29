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

        DB::statement("ALTER TABLE vendor_users MODIFY COLUMN role ENUM('owner','member','product_manager','order_manager','inventory_manager','storekeeper') DEFAULT 'member'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE vendor_users MODIFY COLUMN role ENUM('owner','member','product_manager','order_manager','inventory_manager') DEFAULT 'member'");
    }
};
