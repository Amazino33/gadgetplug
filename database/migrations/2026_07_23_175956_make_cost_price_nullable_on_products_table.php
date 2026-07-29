<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Uses change() rather than raw "ALTER TABLE ... MODIFY": that is MySQL-only
    // syntax and a hard error on SQLite, which the test suite runs on. change()
    // no longer needs doctrine/dbal on Laravel 11+ and is driver-portable.
    // Existing rows keep their current value (0 stays 0); only newly-blank
    // entries going forward will actually be NULL.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::statement('UPDATE products SET cost_price = 0 WHERE cost_price IS NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->nullable(false)->default(0)->change();
        });
    }
};
