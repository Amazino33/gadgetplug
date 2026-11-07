<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ask GadgetPlug's own records. Nothing here is business data: these are the
// conversations, the log of every question and every tool call behind it, and
// the questions it could not answer. The assistant writes nowhere else.
//
// Schema builder only, no raw DDL, so the SQLite test database builds it too.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // panel | whatsapp — the same service answers both.
            $table->string('channel', 20)->default('panel');
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'user_id', 'last_message_at'], 'assistant_conv_owner_idx');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            // user | assistant. Text only — tool traffic lives in assistant_tool_calls.
            $table->string('role', 20);
            $table->text('content');
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('assistant_conversations')->nullOnDelete();
            $table->string('channel', 20)->default('panel');
            $table->text('question');
            $table->text('answer')->nullable();
            // pending | answered | unanswered | refused | error | capped | no_access
            $table->string('status', 20)->default('pending');
            // Whether this question used up one of the vendor's daily allowance.
            // Capped and no-access questions never reached the model, so they don't.
            $table->boolean('counts_toward_cap')->default(true);
            $table->string('model')->nullable();
            $table->json('tools_called')->nullable();
            $table->unsignedSmallInteger('rounds')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('assistant_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('assistant_questions')->cascadeOnDelete();
            $table->string('tool', 64);
            $table->json('input')->nullable();
            // Keys the model sent that the tool does not accept (a vendor_id,
            // say). Dropped before the tool ran; kept here so it can be seen.
            $table->json('dropped_input')->nullable();
            // ok | denied | invalid | error | unknown_tool
            $table->string('status', 20);
            $table->unsignedInteger('result_rows')->nullable();
            $table->boolean('truncated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tool', 'status']);
        });

        Schema::create('assistant_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('assistant_questions')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            // no_tool | no_data | ambiguous | refused | error | too_many_steps
            $table->string('reason', 30);
            $table->text('detail')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['reviewed_at', 'created_at']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            // Null = the platform default (config assistant.daily_question_cap).
            $table->unsignedInteger('assistant_daily_cap')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('assistant_daily_cap');
        });

        Schema::dropIfExists('assistant_unanswered_questions');
        Schema::dropIfExists('assistant_tool_calls');
        Schema::dropIfExists('assistant_questions');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
