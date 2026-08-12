<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_task_submissions', function (Blueprint $table) {
            // The WAT calendar day this share counts for, resolved once at
            // submission. Storing the local date rather than deriving it from
            // submitted_at later means the streak and the daily cap can never
            // be shifted by a timezone or DST change.
            $table->date('share_date')->nullable();

            $table->unsignedInteger('reported_reach')->nullable();

            // Band, points and streak are all resolved and FROZEN at approval.
            // An admin retuning the bands, the cap or the bonus tomorrow must
            // change only what happens next — never restate a settled award.
            $table->foreignId('affiliate_reach_band_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('points_awarded')->nullable();
            $table->unsignedInteger('streak_day')->nullable();
            $table->unsignedInteger('streak_bonus_points')->nullable();

            $table->index(['affiliate_id', 'share_date']);
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_task_submissions', function (Blueprint $table) {
            $table->dropIndex(['affiliate_id', 'share_date']);
            $table->dropConstrainedForeignId('affiliate_reach_band_id');
            $table->dropColumn(['share_date', 'reported_reach', 'points_awarded', 'streak_day', 'streak_bonus_points']);
        });
    }
};
