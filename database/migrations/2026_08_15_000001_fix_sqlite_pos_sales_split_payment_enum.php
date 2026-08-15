<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 2026_05_24_000003_add_split_payment_support assumed SQLite doesn't
    // enforce ENUM and skipped widening it there — wrong: Laravel's SQLite
    // grammar does emit a CHECK constraint for $table->enum(), so every
    // split-payment sale insert failed the test suite's constraint even
    // though MySQL was fixed correctly. Production already has 'split' via
    // that migration's MySQL branch, so this only ever needs to touch SQLite.
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('pos_sales', function (Blueprint $table) {
                $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'split'])->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('pos_sales', function (Blueprint $table) {
                $table->enum('payment_method', ['cash', 'card', 'bank_transfer'])->change();
            });
        }
    }
};
