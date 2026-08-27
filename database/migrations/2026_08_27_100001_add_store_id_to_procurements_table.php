<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A purchase order now says which branch it is being received into.
     *
     * Before this, the destination was decided at approval time from whatever
     * store the approver happened to be working in — so the same order landed
     * in different branches depending on who opened it, and nothing recorded
     * the intent.
     *
     * Left nullable rather than required: the column has to exist and be
     * backfilled before any code can rely on it, and an order raised before
     * this migration has no destination of its own to state. The wizard
     * requires it going forward; approval falls back to the default store for
     * anything older, which is exactly where it would have landed anyway.
     */
    public function up(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('vendor_id')
                ->constrained()->nullOnDelete();
        });

        // Existing orders keep the behaviour they were raised under: the
        // vendor's default store. Done as one correlated update rather than a
        // row-by-row loop so it holds on a table of any size.
        DB::table('procurements')->whereNull('store_id')->update([
            'store_id' => DB::raw('(select id from stores where stores.vendor_id = procurements.vendor_id and stores.is_default = 1 order by id limit 1)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('procurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
