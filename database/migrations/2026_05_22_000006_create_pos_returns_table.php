<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('original_sale_id')->constrained('pos_sales');
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->json('return_items'); // [{product_id, product_name, quantity, unit_price, total}]
            $table->decimal('refund_amount', 12, 2);
            $table->enum('refund_method', ['cash', 'card', 'bank_transfer', 'store_credit']);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_returns');
    }
};
