<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_notification_settings', function (Blueprint $table) {
            // Deliberately separate from vendors.whatsapp, which is the store's
            // public customer-facing contact. This message carries takings, cost
            // of goods and net profit, so it must never default to a number
            // customers are invited to message.
            $table->string('owner_whatsapp')->nullable()->after('storekeeper_whatsapp');

            $table->boolean('notify_daily_summary')->default(true)->after('notify_cancelled');

            // Local clock time the summary is sent. The scheduler ticks hourly and
            // each vendor's own time decides when it fires, so changing this takes
            // effect on the next tick with no redeploy.
            $table->time('daily_summary_time')->default('07:00:00')->after('reminder_frequency');

            // The business date already summarised. Guards the hourly tick against
            // sending twice, and survives a missed tick: if the 07:00 run never
            // happened, the 08:00 one still sends because this is still yesterday.
            $table->date('last_daily_summary_for')->nullable()->after('last_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'owner_whatsapp',
                'notify_daily_summary',
                'daily_summary_time',
                'last_daily_summary_for',
            ]);
        });
    }
};
