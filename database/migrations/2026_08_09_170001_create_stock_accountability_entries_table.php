<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only record of who was held accountable for a stock shortage, for how
// much, and what the owner decided to do about it.
//
// Mirrors financial_ledger_entries' discipline — never updated, never deleted,
// corrected only by a reversing entry, idempotent on its source — but is
// deliberately NOT the same ledger. financial_accounts are bank/cash accounts
// holding real money; stock going missing moves no cash, so posting a shortage
// there would understate a till or bank balance that never actually changed.
// Reporting can read losses from here without corrupting what those accounts
// mean.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_accountability_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The audit row this came from. Unique below — one attribution per
            // count line, so a double-submitted form cannot charge someone twice.
            $table->foreignId('audit_session_id')->constrained()->cascadeOnDelete();

            // Who is being held accountable. Nullable because a shortage can be
            // recorded against the store without naming a person — an unattributed
            // loss is still a loss, and forcing a name would invite guessing.
            $table->foreignId('storekeeper_id')->nullable()->constrained('users')->nullOnDelete();

            // Signed: negative is a shortage (stock missing), positive an overage.
            // Both are recorded — an unexplained surplus is a counting problem
            // worth seeing, not something to quietly discard.
            $table->integer('quantity_variance');

            // Cost per unit frozen at attribution. Product cost_price changes with
            // every restock; an amount owed must not drift afterwards.
            $table->decimal('unit_cost', 12, 2)->nullable();

            // Always non-negative — quantity_variance carries the sign, same
            // convention as financial_ledger_entries.direction.
            $table->decimal('amount', 12, 2)->default(0);

            // written_off  — absorbed as a business loss
            // recoverable  — the storekeeper owes it until settled
            // recorded     — noted for the record, no financial consequence
            // reversal     — cancels an earlier entry; the only way to undo one
            $table->enum('disposition', ['written_off', 'recoverable', 'recorded', 'reversal']);

            $table->enum('reason_code', [
                'Damaged in Store',
                'Suspected Theft',
                'Waybill Shortage',
                'Data Entry Error',
                'Supplier Short Delivery',
                'Other',
            ])->nullable();

            $table->text('note')->nullable();

            // The entry this one reverses, when disposition = reversal.
            $table->foreignId('reverses_entry_id')->nullable()->constrained('stock_accountability_entries')->nullOnDelete();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('occurred_at');
            $table->timestamps();

            // The idempotency backstop. A reversal is a second row for the same
            // audit session, so disposition is part of the key — same reasoning as
            // widening financial_ledger_entries to include direction.
            $table->unique(['audit_session_id', 'disposition'], 'sae_session_disposition_unique');

            $table->index(['vendor_id', 'storekeeper_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_accountability_entries');
    }
};
