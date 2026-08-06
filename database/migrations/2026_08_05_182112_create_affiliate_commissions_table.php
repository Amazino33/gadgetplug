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
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            // Unique — enforces "one commission per order" at the DB level, not
            // just in application logic.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            // Sum of affiliate_commission_items.amount for this order, frozen at
            // creation. A later rate-setting change must never rewrite this.
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'return_window', 'available', 'rejected'])->default('pending');
            $table->timestamp('return_window_started_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
    }
};
