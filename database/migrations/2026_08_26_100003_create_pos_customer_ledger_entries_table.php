<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What each customer owes, as an append-only record.
//
// Deliberately the same shape as accountability_ledger_entries, which already
// solved this exact problem for staff shortages: charges add, recoveries
// subtract, and what remains is a plain SUM of a signed column. Reusing that
// shape means outstanding can never disagree with its own history, because
// there is no stored balance for it to disagree with.
//
// A row here says a named person owes money. A record that can be quietly
// rewritten afterwards is worth nothing to the person it names, so immutability
// is enforced at the model layer too (see PosCustomerLedgerEntry).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pos_customer_id')->constrained('pos_customers')->cascadeOnDelete();

            // Denormalised from the customer so every vendor-scoped query and
            // every tenancy filter can work off this table alone, exactly as
            // accountability_ledger_entries carries its own vendor_id.
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // A string, not an enum: adding a fourth kind later must not mean
            // rewriting a CHECK constraint under SQLite. Validated against a
            // class constant on the model instead.
            $table->string('direction', 16);

            // Signed. Charges positive, payments and write-offs negative, so
            // outstanding is SUM(amount) with no CASE and no per-caller
            // interpretation. The sign is enforced on the model.
            $table->decimal('amount', 12, 2);

            // What produced this row — a PosSale for a charge, a payment record
            // for a repayment. Nullable because an opening balance or a manual
            // adjustment legitimately has no source document.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Where it happened and who took it. The store a debt was incurred
            // at and the store it is repaid at are independent facts, and both
            // are worth keeping — no rule says they must match.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The business date, which is not always the row's creation date —
            // an offline sale syncs later than it happened.
            $table->date('occurred_at');

            $table->string('description')->nullable();

            $table->timestamps();

            // The two reads this table exists for: one customer's history, and
            // every outstanding balance for a vendor.
            $table->index(['pos_customer_id', 'occurred_at']);
            $table->index(['vendor_id', 'direction']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_ledger_entries');
    }
};
