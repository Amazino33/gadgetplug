<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_payouts', function (Blueprint $table) {
            // Snapshot of the affiliate's bank details at the moment they were
            // paid — mirrors vendor_payouts. The affiliate can change their bank
            // details afterwards without rewriting payout history.
            $table->string('bank_name')->nullable()->after('amount');
            $table->string('account_number')->nullable()->after('bank_name');
            $table->string('account_name')->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_payouts', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'account_number', 'account_name']);
        });
    }
};
