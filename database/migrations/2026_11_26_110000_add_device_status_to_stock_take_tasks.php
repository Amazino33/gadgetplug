<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One phone per task (release 2, A7). device_id (added with the offline
// columns) says which phone holds the task. These say what the manager sees
// before releasing it to another phone:
//
//   device_label    the phone, as its browser describes itself ("Android · Chrome")
//   device_seen_at  the last time it downloaded or uploaded anything
//   device_pending  how many counts it still had waiting to upload then
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_tasks', function (Blueprint $table) {
            $table->string('device_label', 80)->nullable()->after('device_id');
            $table->timestamp('device_seen_at')->nullable()->after('device_label');
            $table->unsignedInteger('device_pending')->nullable()->after('device_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_tasks', function (Blueprint $table) {
            $table->dropColumn(['device_label', 'device_seen_at', 'device_pending']);
        });
    }
};
