<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blind_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['a_counting', 'b_counting', 'completed'])->default('a_counting');
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'custom'])->default('daily');
            $table->unsignedSmallInteger('custom_days')->nullable();
            $table->boolean('by_category')->default(false);

            // Stores the randomised product ID sequence so both A and B follow the same order
            $table->json('product_order');

            $table->foreignId('storekeeper_a_id')->constrained('users');
            $table->foreignId('storekeeper_b_id')->nullable()->constrained('users');

            $table->timestamp('a_submitted_at')->nullable();
            $table->timestamp('b_submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blind_count_sessions');
    }
};
