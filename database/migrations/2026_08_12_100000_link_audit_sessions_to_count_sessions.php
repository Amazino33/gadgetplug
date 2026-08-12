<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ties each counted line back to the count that produced it.
//
// audit_sessions is one row per product per count, but nothing recorded which
// count. That made a "count sessions" list impossible: the lines existed and
// the sessions existed, with no way to group one under the other.
//
// Nullable, and deliberately not backfilled. Rows created before this could
// only be matched to a session by guessing from vendor and timestamp, and a
// wrong guess would file someone's count under the wrong session — worse than
// showing it as unlinked.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->foreignId('blind_count_session_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('blind_count_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropForeign(['blind_count_session_id']);
            $table->dropColumn('blind_count_session_id');
        });
    }
};
