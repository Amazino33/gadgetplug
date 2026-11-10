<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 1.3: questions the assistant could not answer — no tool for it, every
 * tool call failed, the model failed, or the reply could not be phrased — kept
 * for the platform admin to review. The question text is copied in, so the
 * record survives the user deleting the conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('assistant_threads')->nullOnDelete();
            $table->foreignId('answer_message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('reason', 32);
            $table->text('detail')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['reviewed_at', 'created_at']);
            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_unanswered_questions');
    }
};
