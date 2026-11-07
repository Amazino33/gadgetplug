<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->boolean('is_prompt')->default(false)->after('content');
            $table->index(['thread_id', 'is_prompt']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['thread_id', 'is_prompt']);
            $table->dropColumn('is_prompt');
        });
    }
};
