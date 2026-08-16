<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A count happens at one branch: you walk one set of shelves and write down
// what is on them. Without a store the reconciliation would true up whichever
// row the default happened to point at, which for a two-branch vendor means
// one branch's count silently rewriting another branch's stock.
//
// Nullable so historical sessions stay readable, then backfilled to each
// vendor's default store — where those counts were in fact taken.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('blind_count_sessions', 'store_id')) {
            Schema::table('blind_count_sessions', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            });
        }

        DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->select('id', 'vendor_id')
            ->get()
            ->each(fn ($store) => DB::table('blind_count_sessions')
                ->where('vendor_id', $store->vendor_id)
                ->whereNull('store_id')
                ->update(['store_id' => $store->id]));
    }

    public function down(): void
    {
        Schema::table('blind_count_sessions', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
