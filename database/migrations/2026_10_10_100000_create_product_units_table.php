<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The ways one product is sold: a bottle, a half crate, a crate.
//
// Stock is still held in the product's base unit (bottles). A unit only says
// how many base units it stands for and what it sells for, so a crate sale
// takes conversion_factor bottles off the shelf. Prices are set by hand, never
// worked out from the bottle price — a crate is normally cheaper per bottle.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // The shop, carried on the row the same way products carry it, so
            // every query can be scoped without a join.
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            // How many base units this is. 1 for the base unit itself.
            $table->unsignedInteger('conversion_factor')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('wholesale_price', 12, 2)->nullable();
            // The row that mirrors the product's own base unit and price. Kept
            // in step by the Product model, never edited as an "extra" unit.
            $table->boolean('is_base')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vendor_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
