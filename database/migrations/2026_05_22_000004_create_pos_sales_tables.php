<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_type')->nullable(); // percentage | fixed
            $table->string('discount_scope')->nullable(); // item | cart
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer']);
            $table->decimal('amount_tendered', 12, 2)->default(0);
            $table->decimal('change_given', 12, 2)->default(0);
            $table->string('bank_transfer_reference')->nullable();
            $table->enum('status', ['completed', 'voided', 'refunded'])->default('completed');
            $table->boolean('synced')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['vendor_id', 'synced']);
            $table->index(['vendor_id', 'completed_at']);
        });

        Schema::create('pos_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
    }
};
