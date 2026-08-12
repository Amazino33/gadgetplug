<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            // ─── Points → cash conversion ───────────────────────────────
            $table->decimal('naira_per_point', 10, 4)->default(1.0000);
            $table->unsignedInteger('min_points_conversion')->default(1000);

            // ─── Daily social share ─────────────────────────────────────
            // Stored as local wall-clock times plus the zone they are read in.
            // The app runs on UTC (config/app.php), so "is it inside today's
            // window?" is only answerable against an explicit zone — WAT here.
            // Keeping the zone as data means a future market change is a
            // settings edit, not a deploy.
            $table->string('share_timezone', 64)->default('Africa/Lagos');
            $table->time('share_window_opens_at')->default('08:00:00');
            $table->time('share_window_closes_at')->default('22:00:00');

            $table->unsignedInteger('daily_share_points_cap')->default(120);

            // Streak: consecutive valid share days. Bonus lands every Nth
            // consecutive day; missing a day resets the count to zero.
            $table->unsignedInteger('streak_bonus_points')->default(50);
            $table->unsignedInteger('streak_bonus_every_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            $table->dropColumn([
                'naira_per_point',
                'min_points_conversion',
                'share_timezone',
                'share_window_opens_at',
                'share_window_closes_at',
                'daily_share_points_cap',
                'streak_bonus_points',
                'streak_bonus_every_days',
            ]);
        });
    }
};
