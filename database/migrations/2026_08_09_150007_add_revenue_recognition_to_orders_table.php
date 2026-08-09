<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Captured for POD orders at the moment they're marked delivered
            // (cash landed at the door, or the rider transferred it) — always
            // 'bank_transfer' for a Paystack order, set automatically, no
            // manual prompt for that path.
            $table->enum('payment_channel', ['cash', 'bank_transfer'])->nullable()->after('posted_at');
            // Separate from posted_at (which already exists here for delivery
            // cost, Prompt 2) — an order can have both a delivery-cost 'out'
            // posting and a revenue 'in' posting, each with its own marker.
            $table->timestamp('revenue_recognized_at')->nullable()->after('payment_channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'revenue_recognized_at']);
        });
    }
};
