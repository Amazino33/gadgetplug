<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Mirrors affiliate_commission_id / affiliate_payout_id exactly.
            // Unique — the DB-level idempotency backstop for "at most one
            // reward credit per submission," same role
            // affiliate_commissions.order_id plays for one-commission-per-order.
            $table->foreignId('affiliate_task_submission_id')->nullable()->unique()
                ->after('affiliate_payout_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_task_submission_id');
        });
    }
};
