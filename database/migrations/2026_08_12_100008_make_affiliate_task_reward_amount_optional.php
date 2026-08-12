<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_tasks', function (Blueprint $table) {
            // reward_amount is now legacy: rewards are Plug Points, and the
            // task builder no longer collects a cash figure. It stays on the
            // table so submissions credited in cash before Plug Points existed
            // remain auditable, but a new task must not be forced to supply it.
            $table->decimal('reward_amount', 10, 2)->default(0)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_tasks', function (Blueprint $table) {
            $table->decimal('reward_amount', 10, 2)->nullable(false)->change();
        });
    }
};
