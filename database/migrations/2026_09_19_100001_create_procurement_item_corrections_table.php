<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What somebody says a line should actually have been.
//
// A row per correction, never an update to the line it corrects. The
// storekeeper wrote 12 and the approver counted 10: both figures are the
// record, and a column that gets overwritten would leave no trace that anyone
// ever disagreed. Same discipline as physical_stock_count_lines, which keeps
// the counted figure beside the system one for exactly this reason, and as
// cash_submissions, which keeps the disputed amount beside the claimed one.
//
// Append-only also makes the ping-pong work at all. A counter-correction is
// just the next row, so "who said what, in what order" falls out of the table
// instead of needing a separate history.
//
// recorded_* is snapshotted from the line rather than read through a join on
// display. It is the same value today, but the point of the column is to be
// the figure as it stood when the correction was made, and a join would
// quietly restate history if a line ever became editable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_item_corrections', function (Blueprint $table) {
            $table->id();
            // Denormalised alongside the item so the batch's corrections load
            // in one query. Every screen wants them by batch, not by line.
            $table->foreignId('procurement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('recorded_quantity');
            $table->decimal('recorded_unit_cost', 12, 2);
            $table->unsignedInteger('verified_quantity');
            $table->decimal('verified_unit_cost', 12, 2);

            $table->foreignId('corrected_by')->constrained('users');
            $table->timestamp('corrected_at');
            $table->text('note')->nullable();

            $table->timestamps();

            // The hot read: the latest correction per line, for one batch.
            //
            // Named explicitly. The convention-generated name off three
            // columns of this table's length comes to 71 characters, and
            // MySQL caps an identifier at 64 — SQLite does not, so this is one
            // of the few things the test suite cannot catch.
            $table->index(
                ['procurement_id', 'procurement_item_id', 'id'],
                'proc_item_corrections_batch_line_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_item_corrections');
    }
};
