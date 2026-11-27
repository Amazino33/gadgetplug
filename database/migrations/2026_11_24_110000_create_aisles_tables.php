<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where things physically sit in a branch: Aisle 1, Fridge, Store Room.
//
// A stock take is planned and counted by place, because that is how a person
// walks a shop. Aisles belong to one branch — the Ikeja shop's Aisle 3 has
// nothing to do with Lekki's — and a product can sit in several of them at
// once (on the shelf and in the store room), which is why the map is its own
// table rather than a column on the product.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aisles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Short label for printed shelf tags and the phone screen ("A01").
            // Optional, but unique within the branch when given.
            $table->string('code', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // Deactivated rather than deleted: past counts still point at it.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code'], 'aisles_store_code_unique');
            $table->index(['store_id', 'is_active', 'sort_order'], 'aisles_store_active_sort_idx');
        });

        Schema::create('aisle_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aisle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // mapping — someone walked the aisle and scanned it there.
            // count   — a counter found it there during an approved count.
            $table->string('source', 16)->default('mapping');
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->timestamps();

            $table->unique(['aisle_id', 'product_id'], 'aisle_products_aisle_product_unique');
            // "Which other aisles is this product in?" — the same-day warning.
            $table->index('product_id', 'aisle_products_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aisle_products');
        Schema::dropIfExists('aisles');
    }
};
