<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a debt repayment actually was — method, branch, collector, day —
// separate from the bare ledger row RecordCustomerPaymentAction posts. Same
// relationship Expense has to its own FinancialLedger posting: the ledger
// entry is the money-arithmetic record, this is what a cash-up query and a
// human reading the till's history need on top of it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_debt_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pos_customer_id')->constrained('pos_customers')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // The branch it was collected at — independent of wherever the
            // debt was originally run up. Nullable for the same reason
            // pos_customer_ledger_entries.store_id is: a vendor with no
            // stores yet still needs to take a payment.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();

            // Links back to the row RecordCustomerPaymentAction wrote, so the
            // ledger entry and this row can never disagree about who created
            // them or drift into two different amounts.
            $table->foreignId('pos_customer_ledger_entry_id')
                ->constrained('pos_customer_ledger_entries')
                ->cascadeOnDelete();

            $table->string('method', 16);
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();

            // The business date, same role as Expense.incurred_at — what
            // CashUpExpectation groups a day's repayments by, since this
            // feature is online-only and so never backdated the way an
            // offline-synced sale can be.
            $table->date('collected_at');

            $table->timestamps();

            // Named explicitly: the generated name runs to 70 characters and
            // MySQL refuses any identifier over 64 (SQLite, which the tests
            // use, does not care — so only a real deploy caught it).
            $table->index(['vendor_id', 'store_id', 'collected_by', 'collected_at'], 'pos_debt_payments_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_debt_payments');
    }
};
