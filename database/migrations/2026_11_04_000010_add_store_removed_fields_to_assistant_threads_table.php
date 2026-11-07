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
        Schema::table('assistant_threads', function (Blueprint $table) {
            $table->timestamp('store_removed_at')->nullable()->after('store_id');
            $table->string('store_removed_name', 120)->nullable()->after('store_removed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table) {
            $table->dropColumn(['store_removed_at', 'store_removed_name']);
        });
    }
};
