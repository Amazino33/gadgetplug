<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->unsignedInteger('total_transactions')->default(0);
            $table->timestamps();

            $table->index(['vendor_id', 'phone']);
            $table->index(['vendor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customers');
    }
};
