<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A product line on a trip.
//
// quantity is what left the shelf and never changes. What is still held, what
// has been paid for and what came back are all derived from the ledger, so no
// figure here can drift from the history that produced it — the same rule the
// customer debt ledger follows.
//
// unit_cost is the exception, and it is not a running total: it is what these
// specific units actually cost, drawn from the cost layers at the moment they
// left. Recording it here is what lets a sale months later book the true cost
// of the goods rather than whatever the product's cost price happens to be on
// the day the money arrives.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('picking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('picking_items');
    }
};
