<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own words for a product — "coke", "titus" — that its name does
 * not contain, so the till and the Purchase Terminal find it by them too.
 * Comma-separated; never shown to customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('search_keywords', 500)->nullable()->after('brand');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('search_keywords');
        });
    }
};
