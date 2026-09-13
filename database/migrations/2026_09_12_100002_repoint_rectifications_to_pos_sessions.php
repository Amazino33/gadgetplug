<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rectifications hang off the session now, and cash_up_sessions goes away.
//
// The parallel table lasted one day and held one test row, which is the cheapest
// this correction was ever going to be. Leaving it would have meant every future
// reader having to know which of two tables a given day lived in.
//
// The ledger rows that point at a cash-up are repointed too. They are append-only
// and this does not rewrite one — source_type names the class that produced the
// row, and that class has been renamed, not re-decided.
return new class extends Migration
{
    public function up(): void
    {
        // Nothing is migrated across: cash_up_sessions is a day old and holds
        // only what this build wrote into it while being tested. Anything real
        // would have needed carrying over, which is exactly why this is being
        // done now rather than in a month.
        Schema::table('cash_up_rectifications', function (Blueprint $table) {
            $table->dropForeign(['cash_up_session_id']);
        });

        Schema::table('cash_up_rectifications', function (Blueprint $table) {
            $table->renameColumn('cash_up_session_id', 'pos_session_id');
        });

        Schema::table('cash_up_rectifications', function (Blueprint $table) {
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->cascadeOnDelete();
        });

        DB::table('accountability_ledger_entries')
            ->where('source_type', 'App\\Models\\CashUpSession')
            ->update(['source_type' => 'App\\Models\\PosSession']);

        Schema::dropIfExists('cash_up_sessions');
    }

    public function down(): void
    {
        Schema::table('cash_up_rectifications', function (Blueprint $table) {
            $table->dropForeign(['pos_session_id']);
        });

        Schema::table('cash_up_rectifications', function (Blueprint $table) {
            $table->renameColumn('pos_session_id', 'cash_up_session_id');
        });

        DB::table('accountability_ledger_entries')
            ->where('source_type', 'App\\Models\\PosSession')
            ->update(['source_type' => 'App\\Models\\CashUpSession']);

        // Deliberately not recreated. Rolling this back restores the column, not
        // the parallel table — a second session table is the thing this migration
        // exists to remove, and bringing it back empty would help nobody.
    }
};
