<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Let a branch be told it is sitting on money before a statement says so.
//
// Waiting for the settlement to surface unremitted cash means the first anyone
// hears of it is at the point it has already become an accusation. Most of the
// time the honest explanation is that nobody got round to it, and a nudge on
// the day fixes it without anybody being asked to account for anything.
//
// Defaults on. A vendor who does not want it can turn it off, but a vendor who
// has never heard of it is exactly who it is for.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_notification_settings', function (Blueprint $table) {
            $table->boolean('notify_unremitted_cash')->default(true)->after('notify_low_stock');
            // When it last went out, so an unremitted balance that persists for
            // a week does not send the same message every hour the scheduler
            // runs. Same shape as the other cadence clocks on this table.
            $table->timestamp('unremitted_cash_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_notification_settings', function (Blueprint $table) {
            $table->dropColumn(['notify_unremitted_cash', 'unremitted_cash_alerted_at']);
        });
    }
};
