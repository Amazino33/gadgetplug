<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Closing the books on a branch for a period.
//
// This sits on top of the settlement statement rather than replacing it. A
// statement is a photograph somebody took because a conversation was about to
// happen; there can be a dozen of them over the same dates and none of them
// means anything has ended. A close says the period is done, and it does
// exactly two things nothing else in the system does:
//
//   1. Freezes the two-sided balance — what was sold against where that value
//      went — as it stood at the moment somebody closed it.
//   2. Names the stock the next period opens with, so opening and closing form
//      a continuous chain instead of every count starting from whatever the
//      perpetual figure happened to say that morning.
//
// It deliberately locks nothing. Completed sales stay append-only and
// untouched, the arbitrary-range statement stays live and re-runnable, and a
// correction dated inside a closed period still lands. Refusing it would only
// push the correction somewhere nobody looks. What a close guarantees is that
// the figures somebody signed off do not move afterwards — not that reality
// stopped moving.
//
// The payload is stored whole rather than as columns, the same way the
// settlement statement stores its own. It is evidence, not a model: nothing
// queries inside it, and giving it a schema would invite the figures to be
// recomputed or "fixed" later, which is the one thing it exists to prevent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_account_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->string('reference')->nullable()->unique();

            $table->timestamp('period_from');
            $table->timestamp('period_to');

            // The close this one follows on from. Null only on a branch's very
            // first close. Stored rather than derived by date so the chain can
            // be walked and verified directly — "which close does this one
            // claim to continue" is a question the row should answer itself.
            $table->foreignId('previous_close_id')->nullable()
                ->constrained('store_account_closes')->nullOnDelete();

            // selected_count — somebody chose which count seeds the opening,
            //   which only ever happens on a first close, because there is no
            //   prior close to inherit from and picking is the honest answer.
            // carried_forward — the previous close's closing count, taken
            //   automatically. No choice offered: a pickable opening is a
            //   pickable answer, and the gap between two periods is exactly
            //   where stock goes missing unnoticed.
            $table->string('opening_source', 20);

            // Nullable for one real case: a branch closing its first period
            // having never held stock. Forcing a count row into existence to
            // satisfy a foreign key would put a fictional opening on the record.
            $table->foreignId('opening_count_id')->nullable()
                ->constrained('physical_stock_counts');

            // Never nullable. A period closed without counting the shelf has
            // only checked the money, and the money side balances perfectly
            // when goods leave without a sale being rung — which is the whole
            // reason the count exists.
            $table->foreignId('closing_count_id')->constrained('physical_stock_counts');

            // The frozen two-sided balance, plus the count-derived variance.
            $table->json('payload');

            // Lifted out of the payload only so the list screen can sort and
            // filter without unpacking every row. The payload stays definitive,
            // and these are copies of it, never a second calculation.
            $table->decimal('value_sold', 12, 2)->default(0);
            $table->decimal('submitted_total', 12, 2)->default(0);
            $table->decimal('till_expenses', 12, 2)->default(0);
            $table->decimal('period_debt', 12, 2)->default(0);
            // Positive is short, negative is over — the same sign convention
            // StoreReconciliation already uses, so the two never read opposite.
            $table->decimal('shortage', 12, 2)->default(0);
            // Approximate by construction: discounts and mid-period price moves
            // make it fuzzy. Kept beside the exact figure, never blended in.
            $table->decimal('variance_at_selling', 12, 2)->default(0);

            $table->foreignId('closed_by')->constrained('users');
            $table->timestamp('closed_at');

            $table->timestamps();

            // Closing the same period twice is the accident worth preventing:
            // it would fork the chain and leave two different openings claiming
            // to seed the same next period. There is no un-close, so this is
            // never in the way of a legitimate second attempt.
            $table->unique(['store_id', 'period_to'], 'store_account_closes_store_period_unique');

            // One count closes one period. Reusing a count to close two would
            // carry the same opening forward twice and hide a period's movement
            // entirely.
            $table->unique('closing_count_id', 'store_account_closes_closing_count_unique');

            $table->index(['store_id', 'period_to']);
            $table->index(['vendor_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_account_closes');
    }
};
