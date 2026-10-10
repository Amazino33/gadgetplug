<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether a shop may sell more than its records say it holds.
//
// Off by default, which is what every shop does today: a sale that would take
// stock below zero is refused. Turned on, the sale goes through and the
// shortfall shows on the Negative Stock report until a delivery or a count
// puts it right. A product can override this either way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('allow_negative_stock')->default(false)->after('pos_min_margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('allow_negative_stock');
        });
    }
};
