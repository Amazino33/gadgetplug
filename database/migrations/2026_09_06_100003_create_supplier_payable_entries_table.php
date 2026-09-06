<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What the reseller owes the supplier, and what has been paid.
//
// Append-only with a derived balance, like every other ledger here: a mistake is
// corrected by posting an opposing row, never by editing one. There is no
// balance column anywhere, so a balance can never drift from the history that
// produced it.
//
// Entirely on the reseller's side. The supplier's own account and books are
// never written by this feature — he is running his shop as before and does not
// know the online channel exists.
//
// unit_cost is frozen at the moment of delivery and never recomputed. The
// supplier will change his price again; what he charged for THESE units on THAT
// day is what is owed, and recomputing later would quietly rewrite a debt.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('supplier_link_id')->constrained()->cascadeOnDelete();

            // 'charge' — units delivered, money now owed to the supplier.
            // 'payment' — money handed to him against that.
            $table->string('entry_type', 20);

            // The delivered line a charge came from. Null on payments, which
            // answer to no single line.
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('quantity')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();

            // charge: quantity x unit_cost, frozen. payment: what was handed over.
            $table->decimal('amount', 12, 2);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();

            // The idempotency backstop, same shape as AccountabilityLedgerEntry.
            // Delivery can fire more than once — a status flipped back and
            // forth, a retried job, two tabs — and the debt must be booked once.
            // Nullable so payments, which have no natural key, are exempt under
            // standard SQL NULL semantics.
            $table->string('idempotency_key')->nullable()->unique();

            // No update path exists, so an updated_at would only ever duplicate
            // created_at.
            $table->timestamp('created_at')->useCurrent();

            // Named explicitly: the generated name would be 68 characters and
            // MySQL refuses anything over 64. SQLite does not enforce that at
            // all, so the test suite cannot catch it — this failed on the first
            // real deploy, after every test had passed.
            $table->index(['vendor_id', 'supplier_link_id', 'entry_type'], 'spe_vendor_link_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payable_entries');
    }
};
