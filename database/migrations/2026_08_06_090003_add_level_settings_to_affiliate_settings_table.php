<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            $table->unsignedInteger('inactivity_demotion_days')->default(21)->after('cookie_window_days');
            // Fraction of an item's margin a boosted commission can never exceed,
            // regardless of level multiplier — protects thin-margin categories.
            $table->decimal('margin_cap_fraction', 4, 2)->default(0.35)->after('min_payout_amount');
        });

        DB::table('affiliate_settings')->where('id', 1)->update([
            'inactivity_demotion_days' => 21,
            'margin_cap_fraction'      => 0.35,
        ]);
    }

    public function down(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            $table->dropColumn(['inactivity_demotion_days', 'margin_cap_fraction']);
        });
    }
};
