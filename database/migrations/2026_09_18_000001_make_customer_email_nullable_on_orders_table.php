<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email stops being something a customer must hand over to buy.
 *
 * Most shoppers here arrive from a Meta ad on a phone and have no reason to
 * type an address they only half remember — every one of them who abandons at
 * that field is a sale lost to a formality. The WhatsApp number is the real
 * contact: it is what order updates are actually sent to.
 *
 * Still required on the Paystack path, because Paystack's own
 * transaction/initialize refuses a transaction without one. Pay-on-delivery
 * has no such constraint, so there it is genuinely optional and NULL is the
 * honest way to record "not given" — an empty string would be a value that
 * later readers (affiliate self-referral matching, CAPI user_data) would have
 * to keep special-casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing NULLs would refuse a NOT NULL column, so they are given the
        // empty string first — the shape this column held before nullable.
        \DB::table('orders')->whereNull('customer_email')->update(['customer_email' => '']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable(false)->change();
        });
    }
};
