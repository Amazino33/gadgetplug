<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->foreignId('audit_session_id')
                ->nullable()
                ->constrained('audit_sessions')
                ->nullOnDelete()
                ->after('description');

            $table->string('reason_code')->nullable()->after('audit_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->dropForeign(['audit_session_id']);
            $table->dropColumn(['audit_session_id', 'reason_code']);
        });
    }
};
