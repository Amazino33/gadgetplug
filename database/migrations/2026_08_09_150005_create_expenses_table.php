<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            // Boundaries matter here — advertising is Facebook/marketing spend;
            // logistics_other is logistics cost NOT a procurement leg and NOT
            // order delivery (both live elsewhere, Prompt 2); other is
            // everything else (rent, airtime, etc). Kept narrow on purpose so
            // the report never double-counts a leg or a delivery entered here
            // by mistake.
            $table->enum('category', ['advertising', 'logistics_other', 'other']);
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->date('incurred_at');
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
