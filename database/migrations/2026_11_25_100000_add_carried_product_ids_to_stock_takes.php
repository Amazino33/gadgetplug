<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A stock take carried over from a closed one counts only what the old one
// left uncounted. The list is kept as it stood when it was carried, so later
// changes to the aisle map cannot quietly widen or shrink what this stock take
// set out to count. Null for an ordinary stock take, whose scope is every
// product on an active aisle of the branch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->json('carried_product_ids')->nullable()->after('carried_from_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->dropColumn('carried_product_ids');
        });
    }
};
