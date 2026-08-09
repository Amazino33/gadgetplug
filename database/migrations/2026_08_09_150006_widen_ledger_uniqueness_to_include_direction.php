<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A source can now legitimately need both an 'out' and an 'in' entry — e.g.
// an Order carries an 'out' for delivery cost (Prompt 2) and an 'in' for
// revenue recognition (Prompt 4), both sourced from the same Order record.
// Under the old (source_type, source_id) uniqueness alone, the second
// postEntry() call would have matched the first entry's row and silently
// returned it instead of creating the real, different movement. Widening to
// (source_type, source_id, direction) still caps each source at one entry
// per direction — the actual guarantee postEntry()'s idempotency needs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->unique(['source_type', 'source_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id', 'direction']);
            $table->unique(['source_type', 'source_id']);
        });
    }
};
