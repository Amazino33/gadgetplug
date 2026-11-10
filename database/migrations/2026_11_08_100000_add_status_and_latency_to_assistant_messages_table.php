<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 1.3: every assistant answer says how it went (App\Enums\Assistant\
 * AnswerStatus) and how long the whole question took, so "which questions
 * failed, and how slow is it" is a query rather than a read through ai_meta.
 * Nullable: rows written before this have neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->string('status', 32)->nullable()->after('ai_meta');
            $table->unsignedInteger('latency_ms')->nullable()->after('status');

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'latency_ms']);
        });
    }
};
