<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The slip the cashier signs becomes the reconciliation, not a list of what the
// system thinks it sold.
//
// pos_z_reports already carried the cash leg — expected, counted, variance — so
// only the terminal half was missing, and without it the signed record showed
// one of the two legs and stayed silent about the other. A cashier signing a
// slip that omits half the money is signing for something nobody can check.
//
// The figures are copied from the session at close rather than recomputed here.
// A slip is a photograph of a moment, and one that recalculated itself whenever
// it was reprinted would not be evidence of anything.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_z_reports', function (Blueprint $table) {
            $table->decimal('terminal_expected', 12, 2)->nullable()->after('cash_variance');
            $table->decimal('terminal_counted', 12, 2)->nullable()->after('terminal_expected');
            $table->decimal('terminal_variance', 12, 2)->nullable()->after('terminal_counted');

            // What the cashier said happened, on the paper the manager reads.
            $table->text('notes')->nullable()->after('terminal_variance');
        });
    }

    public function down(): void
    {
        Schema::table('pos_z_reports', function (Blueprint $table) {
            $table->dropColumn(['terminal_expected', 'terminal_counted', 'terminal_variance', 'notes']);
        });
    }
};
