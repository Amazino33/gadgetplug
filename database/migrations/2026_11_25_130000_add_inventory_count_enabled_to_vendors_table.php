<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The old Inventory Count (blind count), per vendor.
//
// It corrects stock against live stock when a count is finished or resolved,
// so sales made after the shelf was counted are undone. Stock Take does not.
// New shops start without it; every shop that exists today keeps it exactly
// as it is until the platform turns it off for them — so a shop mid-count is
// never cut off, and none loses a screen it uses on the day this ships.
//
// Covers the Inventory Count page, its "count in progress" card, and the
// single-product "Start Inventory Count" on the Products list (the same flaw).
// Past counts stay readable under Audit Sessions either way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('inventory_count_enabled')->default(true); // GadgetPlug: every shop, new ones too, keeps its Inventory Count
        });

        DB::table('vendors')->update(['inventory_count_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('inventory_count_enabled');
        });
    }
};
