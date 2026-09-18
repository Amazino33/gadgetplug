<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The shop's rounding habit changed: closest ₦100 rather than up to the next 990.
//
// Two changes, because a default alone would only reach links made from now on.
// Links already on 'ends_990' are moved across as well — that is the point of
// the change, not a side effect of it. Safe to do in one go here only because
// nothing has been published from those links yet; once listings exist, their
// mirrored price column would need re-resolving too (the price accessor reads
// live, so the shelf price follows immediately either way).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_links', function (Blueprint $table) {
            $table->string('rounding_rule', 40)->default('nearest_100')->change();
        });

        DB::table('supplier_links')
            ->where('rounding_rule', 'ends_990')
            ->update(['rounding_rule' => 'nearest_100']);
    }

    public function down(): void
    {
        // Only the default is put back. Which links were deliberately set to
        // 'ends_990' afterwards is not recorded anywhere, so rewriting the rows
        // would guess at somebody's pricing rather than restore it.
        Schema::table('supplier_links', function (Blueprint $table) {
            $table->string('rounding_rule', 40)->default('ends_990')->change();
        });
    }
};
