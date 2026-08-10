<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The count baseline, captured when the audit row is created.
//
// Until now nothing recorded what stock was *expected* at the moment of the
// count. Variance and value-at-risk were computed by reading
// products.stock_quantity live, at render time — so a row counted on Monday and
// resolved on Thursday measured itself against Thursday's stock, with every
// sale in between silently folded into the "shortage". That is fine for a
// display hint and completely unusable as the basis for holding a person
// accountable for a naira amount.
//
// Nullable with no backfill on purpose: for rows created before this migration
// the baseline genuinely is not knowable, and inventing one — today's stock,
// say — would fabricate variances that read as fact. NULL says "not recorded",
// and the accountability ledger refuses to attribute against it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->integer('system_quantity')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_sessions', function (Blueprint $table) {
            $table->dropColumn('system_quantity');
        });
    }
};
