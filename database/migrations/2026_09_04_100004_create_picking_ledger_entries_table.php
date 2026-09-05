<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Everything that reduces what a picker is holding: money paid, goods brought
// back, and losses the owner has decided to stop chasing.
//
// Append-only, and there is no balance column anywhere — what is still held and
// what is still owed are always the sum of these rows. Copied deliberately from
// PosCustomerLedgerEntry, for the same reason: a stored balance is a second
// source of truth that will eventually disagree with its own history.
//
// Two quantities are tracked per row because a picking settles in two different
// currencies at once:
//
//   quantity   units this row accounts for — settled, returned or written off
//   amount     money this row brought in (payments only; zero otherwise)
//
// A payment carries both, plus the unit_price it settled at. That is what makes
// part-payment work: N15,000 against a N10,000 phone records amount 15,000 and
// quantity 1 at unit_price 10,000, and the N5,000 left over is simply the
// difference between the money paid and the units it covered. It sits against
// this line until another payment completes the next unit.
//
// unit_price is recorded rather than read back from the product because the
// price on the day of payment is what was charged, and the product's price will
// move again afterwards. Without it there would be no record of what a picker
// was actually asked to pay.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picking_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('picking_item_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 20);
            $table->integer('quantity')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            // No update path exists, so an updated_at column would only ever
            // hold a duplicate of created_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['vendor_id', 'direction']);
            $table->index('picking_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('picking_ledger_entries');
    }
};
