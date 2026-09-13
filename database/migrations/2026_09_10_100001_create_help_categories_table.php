<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Platform-wide help content, deliberately with no vendor_id.
//
// Every vendor reads the same guides — they are documentation for the product,
// not data belonging to a store. Giving these tables a vendor_id would put them
// inside the tenant boundary, which is exactly the thing that would hide them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Heroicon name, e.g. 'heroicon-o-truck'. Optional: the vendor-facing
            // list falls back to a neutral icon so an unfilled field never breaks
            // the grid.
            $table->string('icon')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            // The vendor landing page's only query: published categories in order.
            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_categories');
    }
};
