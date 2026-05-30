<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique()->nullable(); // generated post-insert: GP-PROC-00001
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('waybill_image')->nullable();
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->enum('payment_status', ['full', 'part_payment', 'credit'])->default('credit');
            $table->enum('status', ['pending', 'approved', 'voided'])->default('pending');
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'status']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurements');
    }
};
