<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            // Auto tasks skip 'submitted' entirely — the evaluator only ever
            // creates a row already 'approved', crediting in the same
            // transaction, since there's no human review step for them.
            $table->enum('status', ['submitted', 'approved', 'rejected'])->default('submitted');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_reason')->nullable();
            // Copied from affiliate_tasks.level_progress_value at approval
            // time and frozen — a later edit to the task's value must never
            // rewrite an already-approved completion.
            $table->decimal('level_progress_value', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_task_submissions');
    }
};
