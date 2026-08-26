<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which branch the money moved through.
//
// financial_ledger_entries was the one table the August multi-store work
// missed, so cash has been vendor-level ever since: a repayment collected at
// one branch and one collected at another are indistinguishable in the books.
// Same shape as the store_id added to pos_sales then — an int FK, nullable, no
// enum and so no SQLite CHECK rewriting.
//
// Deliberately NOT backfilled, which is where this departs from the pos_sales
// migration. That one could honestly say historical sales belonged to the
// default store, because stock had nowhere else to move from. A cash entry
// carries no such evidence: an expense or a supplier payment predating stores
// has no derivable branch, and guessing "default" would invent a fact and put
// it in the accounts. Null here means "we do not know", which is true.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('financial_ledger_entries', 'store_id')) {
            return;
        }

        Schema::table('financial_ledger_entries', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('financial_account_id')
                ->constrained()->nullOnDelete();

            // Cash is read per account and then narrowed to a branch, which is
            // the order this composite serves.
            $table->index(['financial_account_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['financial_account_id', 'store_id']);
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
