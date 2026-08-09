<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Null means "not set yet" — distinct from a real declared 0, so
            // the report can tell the difference between "no figure entered"
            // and "started with nothing."
            $table->decimal('initial_capital', 12, 2)->nullable()->after('pos_min_margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('initial_capital');
        });
    }
};
