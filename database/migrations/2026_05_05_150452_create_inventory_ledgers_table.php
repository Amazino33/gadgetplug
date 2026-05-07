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
        Schema::create('inventory_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete(); // Multi-tenancy key
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Nullable because an online customer isn't a logged-in staff member
            // First, create the column and explicitly allow blanks (NULL)
            $table->unsignedBigInteger('user_id')->nullable();

            // Then, build the relationship and tell it what to do on delete
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->enum('transaction_type', ['online_sale', 'pos_sale', 'restock', 'audit_correction', 'refund']);

            // Positive for restocks and refunds, negative for sales and corrections
            $table->integer('quantity_change');

            $table->string('reference')->nullable(); // E.g. order ID, restock batch number, etc.
            $table->string('description')->nullable(); // Optional notes about the transaction

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_ledgers');
    }
};
