<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_tasks', function (Blueprint $table) {
            // Rewards are Plug Points now, not cash. reward_amount is left in
            // place as a legacy column rather than dropped — past submissions
            // were credited against it and the audit trail must stay readable.
            $table->unsignedInteger('points_reward')->default(0);

            // The daily social share needs its own type alongside auto/manual.
            // A plain string, not an enum: adding a third task type must never
            // require altering an enum, which is exactly the kind of raw DDL
            // that breaks the SQLite test suite.
            $table->string('task_type', 32)->default('manual');
        });

        // Backfill: existing rows keep behaving as what they already are, and
        // their cash reward becomes the equivalent point reward 1:1 so no
        // configured task silently drops to zero.
        //
        // Done in PHP rather than one UPDATE ... CAST(): the cast spelling
        // differs between MySQL (UNSIGNED) and SQLite (INTEGER), and raw
        // driver-specific SQL in a migration takes the whole test suite down
        // with it. Row counts here are small enough that portability wins.
        DB::table('affiliate_tasks')->orderBy('id')->chunkById(200, function ($tasks) {
            foreach ($tasks as $task) {
                DB::table('affiliate_tasks')->where('id', $task->id)->update([
                    'task_type'     => $task->verification_type,
                    'points_reward' => (int) round((float) $task->reward_amount),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_tasks', function (Blueprint $table) {
            $table->dropColumn(['points_reward', 'task_type']);
        });
    }
};
