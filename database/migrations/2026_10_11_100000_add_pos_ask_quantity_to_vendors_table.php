<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether the till asks how many before it puts a product in the sale.
//
// On by default, which is how a product picked from search has always gone in.
// Off, every product — searched or scanned — goes in at 1 straight away, for
// shops that sell singles and want the fewest keystrokes per item.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('pos_ask_quantity')->default(true)->after('allow_negative_stock');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('pos_ask_quantity');
        });
    }
};
