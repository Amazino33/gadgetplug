<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single-row table (id=1 always) — there is no DB-backed, Filament-
        // editable settings mechanism anywhere else in this app to reuse (only
        // env-driven config/*.php files), so this is a new, minimal pattern
        // rather than an existing one being extended.
        Schema::create('affiliate_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('platform_default_rate', 5, 2)->default(5.00);
            $table->unsignedInteger('return_window_days')->default(3);
            $table->unsignedInteger('cookie_window_days')->default(30);
            $table->decimal('min_payout_amount', 10, 2)->default(2000.00);
            $table->timestamps();
        });

        DB::table('affiliate_settings')->insert([
            'id'                    => 1,
            'platform_default_rate' => 5.00,
            'return_window_days'    => 3,
            'cookie_window_days'    => 30,
            'min_payout_amount'     => 2000.00,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_settings');
    }
};
