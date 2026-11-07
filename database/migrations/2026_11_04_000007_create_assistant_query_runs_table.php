<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_query_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')
                ->constrained('assistant_messages')
                ->cascadeOnDelete();
            $table->string('query_key', 64);
            $table->json('scope_json');
            $table->date('date_from');
            $table->date('date_to');
            $table->json('result_json')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_query_runs');
    }
};
