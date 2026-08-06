<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Links a reseller-purchase debit back to the order it paid for —
            // mirrors affiliate_commission_id/affiliate_payout_id/
            // affiliate_task_submission_id exactly. Needed so the admin order
            // detail page can show the exact debit for a wallet-paid order
            // rather than just its description string.
            $table->foreignId('order_id')->nullable()->after('affiliate_task_submission_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
