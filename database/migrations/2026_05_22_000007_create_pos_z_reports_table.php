<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_z_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions');
            $table->foreignId('cashier_id')->constrained('users');
            $table->date('report_date');
            $table->decimal('cash_sales', 12, 2)->default(0);
            $table->decimal('card_sales', 12, 2)->default(0);
            $table->decimal('bank_transfer_sales', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_vat', 12, 2)->default(0);
            $table->decimal('total_discounts', 12, 2)->default(0);
            $table->decimal('total_returns', 12, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->unsignedInteger('return_count')->default(0);
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->decimal('cash_expected', 12, 2)->default(0);
            $table->decimal('cash_counted', 12, 2)->nullable();
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_z_reports');
    }
};
