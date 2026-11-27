<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recount sent as a spot-check (a random share of the counts that looked
 * fine), not because a count was off. The counter sees an ordinary recount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_tasks', function (Blueprint $table) {
            $table->boolean('is_spot_check')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_tasks', function (Blueprint $table) {
            $table->dropColumn('is_spot_check');
        });
    }
};
