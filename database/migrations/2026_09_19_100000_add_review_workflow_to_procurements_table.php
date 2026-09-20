<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A delivery is now agreed between two people rather than waved through by one.
//
// `status` stops being an enum. Two more states have to fit in it, and an enum
// is the one column type where adding a value means rewriting the table on
// MySQL and rebuilding it on SQLite — cash_submissions already settled this
// argument by using a plain string, so procurement follows it rather than
// inventing a second convention.
//
// `awaiting_user_id` is whose move it is. The ping-pong needs exactly one
// answer to "who is holding this up", and deriving it from the last correction
// every time a badge renders is both slower and easier to get wrong than
// storing the answer the transition already knows.
return new class extends Migration
{
    public function up(): void
    {
        // Guarded by driver rather than written once: MySQL needs real DDL to
        // drop an enum, and running that statement against the SQLite the test
        // suite uses takes the whole suite down with it.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE procurements MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('procurements', function (Blueprint $table) {
                $table->string('status', 20)->default('pending')->change();
            });
        }

        Schema::table('procurements', function (Blueprint $table) {
            $table->foreignId('awaiting_user_id')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['awaiting_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropIndex(['awaiting_user_id', 'status']);
            $table->dropForeign(['awaiting_user_id']);
            $table->dropColumn('awaiting_user_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE procurements MODIFY status ENUM('pending','approved','voided') NOT NULL DEFAULT 'pending'");
        }
    }
};
