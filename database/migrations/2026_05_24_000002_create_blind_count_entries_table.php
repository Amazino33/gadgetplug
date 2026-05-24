<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blind_count_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blind_count_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('product_id')->constrained();

            $table->unsignedInteger('position');
            $table->unsignedInteger('count')->nullable();
            $table->timestamp('counted_at')->nullable();

            $table->timestamps();

            // One entry per user per product per session
            $table->unique(['blind_count_session_id', 'user_id', 'product_id'], 'bce_session_user_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blind_count_entries');
    }
};
