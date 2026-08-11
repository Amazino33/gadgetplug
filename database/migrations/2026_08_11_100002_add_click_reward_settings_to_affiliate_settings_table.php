<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            // Traffic pays money now, so every knob that bounds that spend is
            // admin-editable data rather than a constant — the kill switch
            // first, since an abuse wave needs to be stoppable without a deploy.
            $table->boolean('click_rewards_enabled')->default(true)->after('cookie_window_days');
            $table->decimal('click_reward_amount', 10, 2)->default(2.00)->after('click_rewards_enabled');

            // Per affiliate, per day. Bounds the worst case for one bad actor:
            // whatever they generate beyond this is recorded as engaged but
            // paid at zero.
            $table->decimal('click_reward_daily_cap', 10, 2)->default(200.00)->after('click_reward_amount');

            // Rewarded clicks allowed from one IP, for one affiliate, per day.
            // 1 by default — the same visitor coming back tomorrow is worth
            // paying for, the same visitor reloading the link is not.
            $table->unsignedInteger('click_reward_daily_ip_limit')->default(1)->after('click_reward_daily_cap');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_settings', function (Blueprint $table) {
            $table->dropColumn([
                'click_rewards_enabled',
                'click_reward_amount',
                'click_reward_daily_cap',
                'click_reward_daily_ip_limit',
            ]);
        });
    }
};
