<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stock Take is switched on per vendor, by the platform, off for everyone to
// begin with — so it can be tried on one shop before the rest see it. While it
// is off, its menus are hidden and its routes refused.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('stock_take_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('stock_take_enabled');
        });
    }
};
