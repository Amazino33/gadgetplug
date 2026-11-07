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
            $table->unsignedInteger('daily_message_cap')->default(100)->after('max_tokens');
            $table->unsignedInteger('daily_cost_cap_micros')->default(200000)->after('daily_message_cap');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_global_settings', function (Blueprint $table) {
            $table->dropColumn(['daily_message_cap', 'daily_cost_cap_micros']);
        });
    }
};
