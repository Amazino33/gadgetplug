<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Which branch rang up a counter sale.
//
// Without this, per-store sales would report online orders only, and every
// till transaction would vanish from a branch's numbers — worst for exactly
// the vendors this whole build is for, whose counter trade is the bulk of it.
//
// Nullable: a sale synced from a till that predates this, or an offline queue
// flushed across the upgrade, legitimately has none. An int FK, so no enum and
// no SQLite CHECK rewriting is involved.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_sales', 'store_id')) {
            Schema::table('pos_sales', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            });
        }

        // Historical sales belong to the default store — the only place stock
        // could have moved from before stores existed.
        DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->select('id', 'vendor_id')
            ->get()
            ->each(fn ($store) => DB::table('pos_sales')
                ->where('vendor_id', $store->vendor_id)
                ->whereNull('store_id')
                ->update(['store_id' => $store->id]));
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
