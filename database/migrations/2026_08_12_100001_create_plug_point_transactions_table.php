<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Plug Points ledger — a second, entirely separate economy from
        // the wallet. Same append-only discipline as wallet_transactions:
        // balances are always SUM()'d from here, never stored, and a mistake
        // gets a compensating row rather than an edit. Points are NOT money;
        // the only bridge to cash is an explicit affiliate-initiated
        // conversion (see affiliate_point_conversions).
        Schema::create('plug_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['credit', 'debit']);

            // Signed, mirroring wallet_transactions.amount and InventoryLedger's
            // quantity_change: positive credits raise the balance, negative
            // debits lower it. Whole points only — there is no fractional point.
            $table->integer('points');

            // What produced this row. 'task' covers auto + manual completions,
            // 'daily_share' the social-share task, 'streak_bonus' the milestone
            // top-up, 'conversion' the debit that becomes cash, 'adjustment' an
            // admin correction.
            $table->enum('source', ['task', 'daily_share', 'streak_bonus', 'conversion', 'adjustment']);

            // Every credit can name the row that earned it, exactly as
            // wallet_transactions names its commission/payout/submission.
            $table->foreignId('affiliate_task_submission_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plug_point_transactions');
    }
};
