<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_notification_settings', function (Blueprint $table) {
            // Activation watermark. The undispatched digest scans every order still
            // sitting in paid/confirmed, which on an existing store means the whole
            // historical backlog — orders abandoned months ago would be listed in
            // the first reminder and then re-listed every cycle forever. Only orders
            // placed at or after this moment are ever chased.
            //
            // Nullable rather than defaulted: a null watermark means "no cutoff",
            // which is what a store wants if it deliberately clears this to chase
            // everything once.
            $table->timestamp('remind_orders_from')->nullable()->after('last_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_notification_settings', function (Blueprint $table) {
            $table->dropColumn('remind_orders_from');
        });
    }
};
