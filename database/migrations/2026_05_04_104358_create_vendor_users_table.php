<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', [
                'owner',
                'product_manager',
                'order_manager',
                'inventory_manager',
            ])->default('product_manager');
            $table->timestamps();

            $table->unique(['vendor_id', 'user_id']); // one role per user per vendor
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_users');
    }
};