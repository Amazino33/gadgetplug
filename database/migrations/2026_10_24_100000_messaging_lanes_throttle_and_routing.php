<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Messaging, Phase 5 (staying on Wawp).
//
// delivery_messages:
//  - vendor_id nullable: GadgetPlug's own messages (the rider link, the
//    customer's handover message, ops alerts) are not filed under a vendor.
//  - template_key, priority (lane 1–3), category (transactional|marketing):
//    what the throttle and the marketing guard decide by.
//  - throttle_gap_seconds: the random gap rolled once for this message.
//  - send_failures: failed attempts so far (retries are counted here, not by
//    the queue, because throttle waits are queue releases too).
//  - merged_into_id: a non-urgent message combined into another (digest).
//
// platform_messaging_settings: the WhatsApp brake (paused, why, failures in a
// row, last success/failure) and when the packed-orders reminder last went.
//
// checkouts.customer_confirmation_sent_at: one customer confirmation per
// checkout, claimed atomically like purchase_tracked_at.
//
// The handover message: "dispatched" and the delivery code are one message
// now; stored copies still holding the Phase 4 wording are updated, any
// customised copy is left alone.
return new class extends Migration
{
    private const OLD_CODE_BODY = "Hello {{customer_name}} 👋\n\nYour order *{{order_number}}* is on its way.\n\nYour delivery code is *{{delivery_code}}*\nGive it to the rider only when your items are in your hands — they need it to complete the delivery.\n\n{{collect_line}}\n\nThank you for shopping with GadgetPlug 💚";

    public function up(): void
    {
        Schema::table('delivery_messages', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->change();
            $table->string('template_key', 60)->nullable();
            $table->unsignedTinyInteger('priority')->default(2);
            $table->string('category', 20)->default('transactional');
            $table->unsignedSmallInteger('throttle_gap_seconds')->nullable();
            $table->unsignedTinyInteger('send_failures')->default(0);
            $table->foreignId('merged_into_id')->nullable()->constrained('delivery_messages')->nullOnDelete();
            $table->index(['to_number', 'status'], 'delivery_messages_to_status_index');
        });

        Schema::table('platform_messaging_settings', function (Blueprint $table) {
            $table->timestamp('whatsapp_paused_at')->nullable();
            $table->string('whatsapp_pause_reason')->nullable();
            $table->unsignedSmallInteger('whatsapp_consecutive_failures')->default(0);
            $table->timestamp('whatsapp_last_success_at')->nullable();
            $table->timestamp('whatsapp_last_failure_at')->nullable();
            $table->timestamp('packed_reminder_sent_at')->nullable();
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->timestamp('customer_confirmation_sent_at')->nullable();
        });

        $updated = DB::table('message_templates')
            ->where('key', 'customer_delivery_code')
            ->where('body', self::OLD_CODE_BODY)
            ->update(['body' => \Database\Seeders\MessageTemplateSeeder::defaultBody('customer_delivery_code'), 'updated_at' => now()]);

        if ($updated > 0) {
            logger()->info("Handover message: {$updated} stored copies updated to the merged wording.");
        }
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropColumn('customer_confirmation_sent_at');
        });

        Schema::table('platform_messaging_settings', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_paused_at', 'whatsapp_pause_reason', 'whatsapp_consecutive_failures',
                'whatsapp_last_success_at', 'whatsapp_last_failure_at', 'packed_reminder_sent_at',
            ]);
        });

        Schema::table('delivery_messages', function (Blueprint $table) {
            $table->dropIndex('delivery_messages_to_status_index');
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn(['template_key', 'priority', 'category', 'throttle_gap_seconds', 'send_failures']);
        });
        // vendor_id stays nullable: platform messages may exist by now.
    }
};
