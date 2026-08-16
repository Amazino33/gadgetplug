<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Which store a movement happened in. Nullable: the three (in truth five)
// stock mutators do not write it yet — they are untouched in this phase — so
// rows created between this migration and the phase that rewrites them will
// legitimately have none. Existing rows are backfilled to each row's vendor's
// default store, which is where that stock has always actually been.
//
// An int FK, not an enum: nothing here touches the transaction_type enum, so
// no SQLite CHECK-constraint rewriting is involved.
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so up() stays re-runnable: the backfill below it is the part
        // worth re-running (and worth testing), and a bare addColumn would
        // abort the whole method on the second call.
        if (! Schema::hasColumn('inventory_ledgers', 'store_id')) {
            Schema::table('inventory_ledgers', function (Blueprint $table) {
                $table->foreignId('store_id')
                    ->nullable()
                    ->after('vendor_id')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        // Per vendor rather than a correlated subquery: SQLite and MySQL
        // disagree on UPDATE ... JOIN syntax, and the vendor count is small.
        DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->select('id', 'vendor_id')
            ->get()
            ->each(function ($store) {
                DB::table('inventory_ledgers')
                    ->where('vendor_id', $store->vendor_id)
                    ->whereNull('store_id')
                    ->update(['store_id' => $store->id]);
            });
    }

    public function down(): void
    {
        Schema::table('inventory_ledgers', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
