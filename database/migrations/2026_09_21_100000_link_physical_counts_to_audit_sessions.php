<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which inventory count a settlement count was taken from, when it was not
// typed in by hand.
//
// This codebase counts stock two ways and always has. The blind count on the
// Inventory Count page is the two-person anti-collusion exercise; the
// settlement count is one person at one branch, entered during a settlement.
// They were built for different jobs and neither is wrong.
//
// The account close needs ONE of them, because a period's opening has to be a
// single figure per product. Rather than teach the close to read both shapes —
// which would mean a polymorphic key on a table already live in production, and
// two code paths through every valuation — a completed blind count is copied
// into a settlement count, with cost and selling price frozen at the moment of
// copying exactly as a hand-entered one would be.
//
// This column is what makes that copy idempotent: adopt the same session twice
// and you get the same count back rather than a second one competing with it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physical_stock_counts', function (Blueprint $table) {
            $table->foreignId('blind_count_session_id')
                ->nullable()
                ->after('counted_by')
                ->constrained()
                ->nullOnDelete();

            // One session becomes one count. Named explicitly because the
            // generated name would run past the 64 characters MySQL allows,
            // and SQLite would accept it silently so the test suite would
            // never see the failure.
            $table->unique('blind_count_session_id', 'psc_blind_session_unique');
        });
    }

    public function down(): void
    {
        Schema::table('physical_stock_counts', function (Blueprint $table) {
            $table->dropUnique('psc_blind_session_unique');
            $table->dropConstrainedForeignId('blind_count_session_id');
        });
    }
};
