<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Why a completed sale stopped counting.
//
// A sale is never edited once it is rung. Voiding one used to write 'voided'
// straight onto the sale, which left the reconciliation with no way to tell a
// genuine mistake from a cash sale quietly cancelled after the money was taken
// — the two look identical once the row has been overwritten.
//
// So the change of state is a row here, and pos_sales.status is only ever a
// mirror of the latest one. The sale keeps saying what was rung at the counter;
// this says who withdrew it, when, and why. Same discipline as
// CashUpRectification, which corrects a sale by sitting beside it rather than
// rewriting it.
//
// Returns already have their own record in pos_returns, which stays the detail
// of what came back. A row lands here too, so that "every reason this sale's
// status moved" is one ordered list rather than something a reader has to
// assemble from two tables and hope they got right.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            // Denormalised from the sale so the settlement statement can scope a
            // branch's reversals without joining every sale ever rung there.
            $table->foreignId('store_id')->nullable()->constrained();
            $table->foreignId('pos_sale_id')->constrained('pos_sales');

            // void | return_full | return_partial
            $table->string('type', 20);

            // Kept so the journal reads on its own, without replaying every
            // earlier row to work out what the sale was before this one.
            $table->string('from_status', 20);
            $table->string('to_status', 20);

            // What this withdrew, in money. The sale total for a void, the
            // refunded amount for a return. Snapshotted because it is what the
            // reversal was worth at the time.
            $table->decimal('amount', 12, 2)->default(0);

            // Required by the app for every reversal. A void with no stated
            // reason is the exact thing this table exists to make visible.
            $table->text('reason')->nullable();

            // The pos_returns row this mirrors, when there is one.
            $table->nullableMorphs('source');

            $table->foreignId('performed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['pos_sale_id', 'created_at']);
            $table->index(['vendor_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_reversals');
    }
};
