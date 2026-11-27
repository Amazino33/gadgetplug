<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An approved stock take result is recorded as an audit line, so it appears
// under Inventory → All Counted Lines beside every other count, and shortage
// cases, charging and the reports that read audit_sessions keep working.
//
// This says which stock take task the line came from, the way
// blind_count_session_id says which inventory count. Null for every other
// kind of line.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->foreignId('stock_take_task_id')
                ->nullable()
                ->after('blind_count_session_id')
                ->constrained('stock_take_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_take_task_id');
        });
    }
};
