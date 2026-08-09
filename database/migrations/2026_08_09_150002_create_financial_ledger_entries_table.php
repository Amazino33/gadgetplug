<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['in', 'out']);
            // Always non-negative — direction alone carries the sign, enforced
            // in FinancialLedger::postEntry() and the model's creating guard.
            $table->decimal('amount', 10, 2);
            // Polymorphic rather than one nullable FK per possible source (unlike
            // wallet_transactions) — this ledger is meant to be posted to by many
            // different future sources (orders, procurement, expenses, manual
            // corrections), so a fixed set of FK columns would need extending
            // every time. NULL/NULL rows (manual entries with no source) are
            // exempt from the uniqueness below — standard SQL NULL semantics
            // treat every NULL as distinct, which is exactly what's wanted here.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description')->nullable();
            // The date money actually moved, not when the row was written —
            // may differ if a source is recognized/backdated after the fact.
            $table->date('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The idempotency backstop: at most one ledger entry per source.
            // FinancialLedger::postEntry() is the only sanctioned writer and
            // checks this first, but the constraint is what actually prevents
            // a double-post under real concurrency, not the pre-check alone.
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger_entries');
    }
};
