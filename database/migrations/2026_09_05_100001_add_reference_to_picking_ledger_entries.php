<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The till's own id for a payment, so one taken offline cannot be applied twice.
//
// A single payment settles several lines and so writes several rows, all
// carrying the same reference — this is not unique, and dedupe asks whether any
// row already has it rather than relying on the database to refuse a second.
// That is deliberate: the check has to happen inside the same transaction that
// does the allocation anyway, because two tills syncing the same queued payment
// would otherwise both pass a uniqueness test and both allocate.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('picking_ledger_entries', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('direction');
            $table->index(['vendor_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('picking_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['vendor_id', 'reference']);
            $table->dropColumn('reference');
        });
    }
};
