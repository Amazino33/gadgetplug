<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What each till last said it still holds unsynced, per branch.
//
// A till keeps sales in its own storage until it has signal, and the server
// cannot see them until they arrive. A stock take approved while a till still
// holds a sale rung before the count would correct the shelf for that sale
// and then deduct it again when it syncs. So the till reports its queue every
// time it tries to sync, and the approval screen warns while any till at the
// branch last reported sales waiting.
//
// One row per device per branch. device_id is made up by the till and kept
// in its own storage.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_till_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('pending_sales')->default(0);
            $table->timestamp('oldest_pending_at')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->unique(['device_id', 'store_id'], 'ptss_device_store_unique');
            $table->index(['store_id', 'pending_sales'], 'ptss_store_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_till_sync_states');
    }
};
