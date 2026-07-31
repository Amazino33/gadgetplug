<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a cashier negotiate a price at the till without ever selling at a loss.
//
// pos_min_margin_percent is the margin a vendor insists on keeping on a
// negotiated sale. Default 0 means "you may haggle all the way down to cost,
// but not a naira below it" — the rule as originally asked for. Raise it to
// keep a guaranteed slice on every negotiated sale.
//
// allow_pos_price_override defaults to true because the floor already makes
// negotiation loss-proof; the flag exists to LOCK individual products whose
// price must never move (fixed-price or regulated lines), rather than to make
// vendors opt in product by product.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('pos_min_margin_percent', 5, 2)
                ->default(0)
                ->after('pos_blind_count_participants');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allow_pos_price_override')
                ->default(true)
                ->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('pos_min_margin_percent');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allow_pos_price_override');
        });
    }
};
