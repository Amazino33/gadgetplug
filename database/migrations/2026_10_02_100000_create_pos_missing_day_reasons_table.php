<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Why a cashier started selling without recording an earlier end of day.
//
// The till asks for every day in the last fortnight that was opened or sold on
// but never counted, and lets the cashier go on only with a reason. One row per
// missing day per morning it was put off, so a manager sees how often a day has
// been pushed back, not only that it was.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_missing_day_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            // The day that was not recorded, and the day the cashier said why.
            $table->date('business_date');
            $table->date('given_on');
            $table->text('reason');
            $table->timestamps();

            // A retried open must not log the same excuse twice.
            $table->unique(['cashier_id', 'store_id', 'business_date', 'given_on'], 'pos_missing_reason_once_a_day');
            $table->index(['vendor_id', 'store_id', 'business_date'], 'pos_missing_reason_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_missing_day_reasons');
    }
};
