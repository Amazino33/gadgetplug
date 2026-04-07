<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete(); // Multi-tenancy key
            $table->foreignId('category_id')->constrained();

            $table->string('name');
            $table->string('slug');
            $table->string('brand')->nullable();

            $table->decimal('price', 12, 2);
            $table->integer('stock_quantity')->default(0);
            $table->json('specifications')->nullable();

            $table->timestamps();

            // Prevents a vendor from having duplicate slugs for their own products
            $table->unique(['vendor_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
