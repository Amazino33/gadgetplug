<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Words staff searched with before picking a product, learned so the next
 * search with them finds it first. See App\Services\Products\SearchShortcuts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_search_shortcuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Cleaned the way search cleans text: "titus", "coke 50".
            $table->string('term', 60);
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'term', 'product_id']);
            $table->index(['product_id', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_search_shortcuts');
    }
};
