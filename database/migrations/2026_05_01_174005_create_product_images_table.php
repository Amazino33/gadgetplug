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
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            // Links this image to a specific product and deletes the image if the product is deleted
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); 
            
            $table->string('image_path');
            $table->boolean('is_cover')->default(false);
            $table->integer('sort_order')->default(0); // For drag-and-drop rearranging
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
