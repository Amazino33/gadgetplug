<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();

            // Storekeepers are often not logged in, so WhatsApp is the notification
            // channel. Kept separate from vendors.whatsapp, which is the store's
            // public-facing contact shown to customers.
            $table->string('storekeeper_whatsapp')->nullable();

            // Which events reach the storekeeper. Each is independently switchable
            // so a store can start with just the essentials and add more later.
            $table->boolean('notify_new_order')->default(true);
            $table->boolean('notify_undispatched')->default(true);
            $table->boolean('notify_low_stock')->default(false);
            $table->boolean('notify_cancelled')->default(false);

            // How long an order may sit unshipped before it counts as needing
            // follow-up and appears in the reminder digest.
            $table->unsignedSmallInteger('undispatched_after_hours')->default(6);

            $table->enum('reminder_frequency', ['hourly', '3h', '6h', 'daily'])->default('3h');

            // Reminders are suppressed outside these hours so a stalled order does
            // not wake anyone at 3am. Instant event alerts ignore this window,
            // since those follow a real customer action worth knowing about.
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->time('quiet_from')->default('08:00:00');
            $table->time('quiet_until')->default('20:00:00');

            // Drives the cadence: the scheduler runs hourly and compares this
            // against reminder_frequency, so changing frequency takes effect
            // immediately without rescheduling anything.
            $table->timestamp('last_reminder_sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_notification_settings');
    }
};
