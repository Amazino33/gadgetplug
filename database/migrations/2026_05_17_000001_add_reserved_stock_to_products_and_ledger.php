<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add reserved_stock counter to products
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('reserved_stock')->default(0)->after('stock_quantity');
        });

        // 2. Extend ledger transaction_type enum to include reservation events
        DB::statement("ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type
            ENUM('online_sale','pos_sale','restock','audit_correction','refund','reserved','dispatched','reservation_released')
            NOT NULL");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reserved_stock');
        });

        DB::statement("ALTER TABLE inventory_ledgers MODIFY COLUMN transaction_type
            ENUM('online_sale','pos_sale','restock','audit_correction','refund')
            NOT NULL");
    }
};
