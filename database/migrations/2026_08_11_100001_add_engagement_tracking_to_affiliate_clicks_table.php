<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            // A click row stops being a bare arrival record and becomes the unit
            // the micro-reward is paid against — exactly once. `page_views`
            // counts real page loads made under this click; the reward only
            // fires on the second one, which is the first that proves the
            // visitor actually looked at something and clicked on. Landing and
            // leaving stays worth nothing.
            $table->string('session_id')->nullable()->after('affiliate_id');
            $table->unsignedInteger('page_views')->default(0)->after('landing_url');

            // Set the moment the click is *resolved*, whether or not money
            // followed — a capped or disallowed click is stamped with a zero
            // reward_amount so it is never reconsidered. Null means "still only
            // a landing".
            $table->timestamp('qualified_at')->nullable()->after('page_views');
            $table->decimal('reward_amount', 10, 2)->nullable()->after('qualified_at');

            $table->index('session_id');

            // Serves both caps: per-affiliate spend today, and the per-IP limit
            // that stops one person farming ₦2 by re-opening the same link.
            $table->index(['affiliate_id', 'qualified_at']);
            $table->index(['ip_address', 'qualified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            $table->dropIndex(['ip_address', 'qualified_at']);
            $table->dropIndex(['affiliate_id', 'qualified_at']);
            $table->dropIndex(['session_id']);

            $table->dropColumn(['session_id', 'page_views', 'qualified_at', 'reward_amount']);
        });
    }
};
