<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_logistics_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_id')->constrained()->cascadeOnDelete();
            $table->string('route_label');
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            // Both null until "Record Logistics Payment" posts this leg to the
            // ledger — nullable rather than a boolean flag so the account used
            // is preserved, not just the fact that it was paid.
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_logistics_legs');
    }
};
