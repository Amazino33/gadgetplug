<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_suspended_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->unsignedTinyInteger('slot'); // 1, 2, or 3
            $table->string('label')->nullable();
            $table->json('cart_data');
            $table->timestamps();

            $table->unique(['vendor_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_suspended_sales');
    }
};
