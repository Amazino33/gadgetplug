<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A product's slug now follows its online name (set by GadgetPlug), so a
// product can change URL. Every slug it leaves behind is kept here, and the
// old URL answers with a 301 to the current one — links already shared on
// WhatsApp and already indexed by search engines keep working.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('old_slug');
            $table->timestamps();

            $table->unique(['product_id', 'old_slug']);
            $table->index('old_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_slug_redirects');
    }
};
