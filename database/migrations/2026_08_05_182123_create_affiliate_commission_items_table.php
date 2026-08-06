<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('affiliate_commission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_commission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            // Each line resolves its own rate (product override -> category ->
            // platform default), since a single order can span products/
            // categories with different rates. Frozen at creation.
            $table->decimal('rate', 5, 2);
            $table->decimal('base_amount', 10, 2);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_commission_items');
    }
};
