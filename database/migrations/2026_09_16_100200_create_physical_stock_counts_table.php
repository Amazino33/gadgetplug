<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What was actually on the shelf, counted by hand, against what the system
// believed was on the shelf.
//
// The second of the two independent signals a settlement rests on. Cash alone
// cannot see a sale that never touched the till: goods walk out, no record is
// ever made, and expected cash is calculated from records that do not exist, so
// it balances perfectly. Only a count of the goods themselves catches that.
//
// Counted quantities are frozen the moment they are entered, and the system
// figure is snapshotted beside them rather than recomputed on read — a variance
// that silently corrected itself as later stock moved would be no evidence of
// anything.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('counted_by')->constrained('users');

            // The window this count is the closing position for. Matches the
            // settlement statement's range, so the two can be read together.
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            $table->timestamp('counted_at');
            $table->text('note')->nullable();

            // The counted figures never change. Only the decision about them
            // moves, which is why the status lives here and not on the lines —
            // the same shape as a cash submission, where the amount is frozen
            // and the status travels.
            $table->string('status', 20)->default('submitted');

            // Whoever signed it off. Never the person who counted: a shortage
            // on a shelf you counted is one you must not also approve away.
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();

            // written_off | charged | none — what was decided about the gap.
            // Recorded even when nothing was missing, so a count that found
            // nothing is distinguishable from one nobody decided about.
            $table->string('outcome', 20)->nullable();
            $table->foreignId('charged_to')->nullable()->constrained('users');
            $table->text('decision_note')->nullable();

            // Set once the stock movement has actually been posted, so a
            // half-finished approval cannot be mistaken for a complete one.
            $table->timestamp('adjusted_at')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['store_id', 'period_end']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('physical_stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();

            // What the person counted.
            $table->integer('counted_quantity');
            // What the system said at the moment of counting. Snapshotted, not
            // derived: this is the figure the count actually disagreed with.
            $table->integer('system_quantity');
            // Unit cost at the time, so a variance can be valued in money
            // without re-running FIFO over a period that has since moved on.
            $table->decimal('unit_cost', 12, 2)->nullable();

            // What it would have sold for. The cost says what the loss is worth
            // to the books; this says what the till should have taken if the
            // goods left as a sale — which is the figure that sits beside the
            // cash shortage and explains it.
            $table->decimal('unit_price', 12, 2)->nullable();

            // Named explicitly: the generated name would be 68 characters and
            // MySQL refuses anything over 64. SQLite has no such limit, so the
            // test suite passes this migration and only a real MySQL database
            // ever sees the failure.
            $table->unique(['physical_stock_count_id', 'product_id'], 'psc_lines_count_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_stock_count_lines');
        Schema::dropIfExists('physical_stock_counts');
    }
};
