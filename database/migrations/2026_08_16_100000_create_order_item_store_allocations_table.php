<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which store supplied which units of an order line.
//
// A row per (line, store) rather than a store_id column on order_items,
// because a line can be filled from more than one branch: six units against a
// store holding five becomes five from one and one from another. A single
// column could only ever record one of those and would quietly lose the rest.
//
// This is the source of truth for fulfilment. Reservation writes it, dispatch
// and release read it back, and per-store sales are derived from it — so the
// store is decided once, at reservation, and never re-guessed from is_default
// afterwards.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_store_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->integer('quantity');
            $table->timestamps();

            // One row per store per line; a second helping from the same store
            // is an increase to the existing row, not another row.
            $table->unique(['order_item_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_store_allocations');
    }
};
