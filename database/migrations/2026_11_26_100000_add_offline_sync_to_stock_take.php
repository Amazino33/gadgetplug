<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Counting offline, release 2.
//
// The phone counts with no network and tells the server afterwards, in
// batches of operations. Each operation carries an id the phone made up; the
// server applies it once, records what happened, and answers a repeat of it
// with that same answer — a batch whose response was lost is simply sent again.
//
// stock_take_entries
//   device_counted_at   the phone's own clock when it was counted, as sent
//   counted_at_flagged  the phone's time (corrected to the server's clock) fell
//                       outside the task's life, so the upload time was used
//                       instead — shown to the approver
//
// stock_take_sync_ops   one row per operation applied, keyed by its id
//
// stock_take_tasks.device_id  the phone the task is being counted on (A7)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_entries', function (Blueprint $table) {
            $table->timestamp('device_counted_at')->nullable();
            $table->boolean('counted_at_flagged')->default(false);
        });

        Schema::create('stock_take_sync_ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_take_task_id')->constrained()->cascadeOnDelete();
            $table->uuid('op_id')->unique('stso_op_id_unique');
            // set | remove
            $table->string('type', 16);
            // synced | rejected
            $table->string('status', 16);
            $table->string('reason', 255)->nullable();
            $table->foreignId('entry_id')->nullable()->constrained('stock_take_entries')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['stock_take_task_id', 'created_at'], 'stso_task_created_idx');
        });

        Schema::table('stock_take_tasks', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_tasks', function (Blueprint $table) {
            $table->dropColumn('device_id');
        });

        Schema::dropIfExists('stock_take_sync_ops');

        Schema::table('stock_take_entries', function (Blueprint $table) {
            $table->dropColumn(['device_counted_at', 'counted_at_flagged']);
        });
    }
};
