<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A cashier short on the drawer owes money in exactly the same sense as a
// storekeeper short on stock, so it belongs in the same ledger rather than a
// parallel one. Reuse over duplication: this table is already append-only,
// already signed so that outstanding is a plain SUM, already idempotent on a
// natural key, and already the place an owner looks to ask "what does this
// member of staff owe me?". A second cash-only ledger next to it would mean two
// answers to that question.
//
// Two entry types rather than one 'cash_variance', because the existing sign
// invariant is worth more than the saved row type: charges may not be negative,
// reductions may not be positive, and outstanding is therefore SUM(amount) with
// no CASE anywhere. A single signed cash_variance type would have forced that
// rule to be relaxed for every type, weakening a guarantee the stock side
// already depends on. A shortage increases what is owed; an overage reduces it.
//
// Uses ->change() on the enum rather than a raw MySQL ALTER: Laravel's SQLite
// grammar emits a real CHECK constraint for enum(), so a MySQL-only fix would
// leave every cash-up posting failing in the test suite — exactly what happened
// when 'split' was added to pos_sales and had to be repaired afterwards.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accountability_ledger_entries', function (Blueprint $table) {
            $table->enum('entry_type', [
                'charge',
                'recovery_cash',
                'recovery_salary',
                'recovery_manual',
                'writeoff_conversion',
                'cash_shortage',
                'cash_overage',
            ])->change();
        });

        Schema::table('accountability_ledger_entries', function (Blueprint $table) {
            // Which branch the loss happened at. The table predates multi-store
            // and had no need for it while every charge came from a count that
            // knew its own store; a cash-up is inherently per-branch, and an owner
            // with three shops needs to know which drawer was short. Nullable, so
            // every existing row stays valid — the same shape as the additive
            // store_id on financial_ledger_entries and inventory_ledgers.
            $table->foreignId('store_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();

            // What produced this row. case_id answers it for a stock shortage, but
            // a cash variance has no case and never will — it has a cash-up
            // session. A polymorphic pointer generalises this without a new
            // nullable id column per source, and matches the source_type/source_id
            // pair pos_customer_ledger_entries settled on for the same problem.
            $table->string('source_type')->nullable()->after('case_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
        });

        Schema::table('accountability_ledger_entries', function (Blueprint $table) {
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accountability_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
        });

        Schema::table('accountability_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn(['store_id', 'source_type', 'source_id']);
        });

        Schema::table('accountability_ledger_entries', function (Blueprint $table) {
            $table->enum('entry_type', [
                'charge',
                'recovery_cash',
                'recovery_salary',
                'recovery_manual',
                'writeoff_conversion',
            ])->change();
        });
    }
};
