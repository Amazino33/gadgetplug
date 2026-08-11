<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nullable, not defaulted — ProductVelocityService's own method defaults
// (30/5/30, buffer = lead time) apply whenever a vendor hasn't set these, so
// there's nothing to backfill and no vendor is silently opted into a
// different number than the one already documented as the default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedInteger('restock_window_days')->nullable()->after('initial_capital');
            $table->unsignedInteger('restock_lead_time_days')->nullable()->after('restock_window_days');
            $table->unsignedInteger('restock_target_cover_days')->nullable()->after('restock_lead_time_days');
            $table->unsignedInteger('restock_safety_buffer_days')->nullable()->after('restock_target_cover_days');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'restock_window_days',
                'restock_lead_time_days',
                'restock_target_cover_days',
                'restock_safety_buffer_days',
            ]);
        });
    }
};
