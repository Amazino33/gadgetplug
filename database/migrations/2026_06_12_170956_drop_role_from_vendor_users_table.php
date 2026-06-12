<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'member', 'product_manager', 'order_manager', 'inventory_manager', 'storekeeper',])->default('member');
        });
    }
};
