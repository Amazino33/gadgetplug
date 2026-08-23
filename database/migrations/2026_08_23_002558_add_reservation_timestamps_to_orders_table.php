<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Set once, by ReserveStockAction, the first time this order's
            // stock is actually held — not order creation, since a Paystack
            // order sits 'pending' with nothing reserved until payment clears.
            // This is what a stale-reservation sweep measures against.
            $table->timestamp('reserved_at')->nullable()->after('idempotency_key');

            // Set when that sweep frees the hold for a reservation nobody ever
            // collected. Left null on manual cancellation, which already
            // releases via a different path (OrderObserver) and doesn't need
            // this marker — it exists purely so the sweep never processes the
            // same order twice.
            $table->timestamp('reservation_released_at')->nullable()->after('reserved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reserved_at', 'reservation_released_at']);
        });
    }
};
