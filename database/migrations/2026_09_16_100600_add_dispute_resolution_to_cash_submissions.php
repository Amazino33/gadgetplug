<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How a contested handover finally ends.
//
// A dispute was recordable but never settleable: the row sat as 'disputed'
// forever, the money stayed on the submitter, and nothing in the system could
// ever close it. The statement flagged it for a Checkmate conversation and then
// offered no way to record what that conversation decided.
//
// The two amounts are still never edited. What each person said at the time
// remains the record; this only adds what was later agreed about the difference
// between them, and who agreed it — the same shape as a stock count, where the
// counted figures are frozen and only the decision moves.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_submissions', function (Blueprint $table) {
            // accepted | charged | written_off
            $table->string('resolution_outcome', 20)->nullable()->after('disputed_amount');
            $table->foreignId('resolved_by')->nullable()->after('resolution_outcome')->constrained('users');
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->text('resolution_note')->nullable()->after('resolved_at');

            $table->index(['store_id', 'resolution_outcome']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_submissions', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'resolution_outcome']);
            $table->dropForeign(['resolved_by']);
            $table->dropColumn(['resolution_outcome', 'resolved_by', 'resolved_at', 'resolution_note']);
        });
    }
};
