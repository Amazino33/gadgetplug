<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assistant_query_runs', function (Blueprint $table) {
            $table->boolean('has_data')->default(true)->after('success');
            $table->string('failure_reason', 64)->nullable()->after('has_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assistant_query_runs', function (Blueprint $table) {
            $table->dropColumn(['has_data', 'failure_reason']);
        });
    }
};
