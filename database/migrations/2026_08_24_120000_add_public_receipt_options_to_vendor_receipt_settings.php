<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings for the customer-facing copy the QR opens: whether to print the code
// at all, and what the store wants on the page once it is scanned.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_receipt_settings', function (Blueprint $table) {
            // ── The QR on the paper ───────────────────────────────────────────
            $table->boolean('show_qr')->default(true)->after('show_item_unit_price');
            $table->string('qr_caption')->nullable()->after('show_qr');

            // ── The page it opens ─────────────────────────────────────────────
            $table->string('banner_image')->nullable()->after('qr_caption');
            $table->string('banner_link')->nullable()->after('banner_image');
            $table->string('cta_label')->nullable()->after('banner_link');
            $table->string('cta_link')->nullable()->after('cta_label');

            // ── Loyalty ───────────────────────────────────────────────────────
            // Progress is counted from pos_customers.total_transactions, which
            // the till already maintains — so the number shown is a real one,
            // not a decoration. A walk-in with no customer record has no history
            // to show, which is exactly the nudge to give their number next time.
            $table->boolean('loyalty_enabled')->default(false)->after('cta_link');
            $table->unsignedTinyInteger('loyalty_goal')->default(10)->after('loyalty_enabled');
            $table->string('loyalty_reward_text')->nullable()->after('loyalty_goal');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_receipt_settings', function (Blueprint $table) {
            $table->dropColumn([
                'show_qr', 'qr_caption',
                'banner_image', 'banner_link', 'cta_label', 'cta_link',
                'loyalty_enabled', 'loyalty_goal', 'loyalty_reward_text',
            ]);
        });
    }
};
