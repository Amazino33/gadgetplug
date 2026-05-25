<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE vendor_users MODIFY COLUMN role ENUM('owner','member','product_manager','order_manager','inventory_manager','storekeeper') DEFAULT 'member'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE vendor_users MODIFY COLUMN role ENUM('owner','member','product_manager','order_manager','inventory_manager') DEFAULT 'member'");
    }
};
