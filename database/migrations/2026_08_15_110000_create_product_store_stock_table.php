<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One stock row per (product, store) — the spine of multi-store inventory.
//
// Additive only in this phase: products.stock_quantity and
// products.reserved_stock remain the live source of truth, and every mutator
// and reader still uses them untouched. This table is populated and asserted
// against, so the two agree exactly, but nothing reads it yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_store_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_store_stock');
    }
};
