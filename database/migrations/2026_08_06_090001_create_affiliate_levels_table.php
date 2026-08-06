<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Lifetime cleared sales value (base_amount, not commission) an
            // affiliate must reach to be promoted into this level.
            $table->decimal('target', 12, 2);
            // Multiplier applied to the resolved base rate (Model A) — e.g.
            // 1.00 for the entry level, 1.20 for a level that boosts by 20%.
            $table->decimal('rate_value', 5, 2)->default(1.00);
            // Rank order, ascending — lowest tier first. Demotion/promotion
            // step exactly one sort_order position, never further in one move.
            $table->unsignedInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_levels');
    }
};
