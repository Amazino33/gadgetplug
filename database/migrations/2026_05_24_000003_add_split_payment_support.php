<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the enum to include 'split'
        DB::statement("ALTER TABLE pos_sales MODIFY COLUMN payment_method ENUM('cash','card','bank_transfer','split') NOT NULL");

        Schema::create('pos_sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'bank_transfer']);
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('pos_sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_payments');
        DB::statement("ALTER TABLE pos_sales MODIFY COLUMN payment_method ENUM('cash','card','bank_transfer') NOT NULL");
    }
};
