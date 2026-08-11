<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Ties an engaged-visit credit back to the exact click that earned
            // it — same shape as affiliate_commission_id / affiliate_payout_id /
            // affiliate_task_submission_id / order_id. Every credit in this
            // ledger can name its source row.
            $table->foreignId('affiliate_click_id')->nullable()->after('order_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_click_id');
        });
    }
};
