<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A conversation belongs to one business. Without this, a thread was only
// "this user's", so an owner of two vendors saw every thread in both panels
// and the assistant answered from both vendors' stores at once.
//
// Backfill, existing threads:
//   1. a thread with a store → that store's vendor;
//   2. otherwise, if the user owns exactly one vendor → that vendor;
//   3. otherwise left null. Null threads are only listed where there is no
//      tenant to filter by, so nothing is shown in the wrong business.
//
// Schema builder and query builder only, so SQLite builds it too.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'vendor_id']);
        });

        DB::table('assistant_threads')
            ->whereNull('vendor_id')
            ->whereNotNull('store_id')
            ->orderBy('id')
            ->each(function ($thread) {
                $vendorId = DB::table('stores')->where('id', $thread->store_id)->value('vendor_id');

                if ($vendorId) {
                    DB::table('assistant_threads')->where('id', $thread->id)->update(['vendor_id' => $vendorId]);
                }
            });

        DB::table('assistant_threads')
            ->whereNull('vendor_id')
            ->orderBy('id')
            ->each(function ($thread) {
                $owned = DB::table('vendors')->where('user_id', $thread->user_id)->pluck('id');

                if ($owned->count() === 1) {
                    DB::table('assistant_threads')->where('id', $thread->id)->update(['vendor_id' => $owned->first()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'vendor_id']);
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
