<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_global_settings', function (Blueprint $table) {
            $table->unsignedInteger('history_window')->default(20)->after('is_enabled');
            $table->string('model_name', 80)->default('openai/gpt-4o-mini')->after('history_window');
            $table->unsignedInteger('max_tokens')->default(800)->after('model_name');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_global_settings', function (Blueprint $table) {
            $table->dropColumn(['history_window', 'model_name', 'max_tokens']);
        });
    }
};
