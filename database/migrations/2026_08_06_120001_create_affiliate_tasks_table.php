<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('verification_type', ['auto', 'manual']);
            // Auto tasks only — which derived metric to check, and the
            // threshold it must reach. Null for manual tasks.
            $table->enum('auto_metric', ['cleared_sales_value', 'completed_sales_count'])->nullable();
            $table->decimal('auto_target', 12, 2)->nullable();
            $table->decimal('reward_amount', 10, 2);
            $table->boolean('counts_toward_level')->default(false);
            // How much this task is "worth" toward the level target, in the
            // same currency unit as lifetime sales value — a Facebook post
            // has no natural sales-value equivalent otherwise. Frozen onto
            // the submission at approval time; this column can change later
            // without rewriting past completions.
            $table->decimal('level_progress_value', 12, 2)->nullable();
            $table->unsignedInteger('max_completions_per_affiliate')->nullable();
            $table->unsignedInteger('cooldown_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_tasks');
    }
};
