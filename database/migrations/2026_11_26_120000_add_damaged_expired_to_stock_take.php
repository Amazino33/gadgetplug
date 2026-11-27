<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Damaged and expired items found while counting, kept apart from the good
 * count (base units). The variance compares everything on the shelf with the
 * system; on approval the damaged and expired are written off, so stock ends
 * at the good count and the loss is recorded for what it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_entries', function (Blueprint $table) {
            $table->decimal('damaged_qty_base', 12, 3)->default(0)->after('counted_qty_base');
            $table->decimal('expired_qty_base', 12, 3)->default(0)->after('damaged_qty_base');
        });

        Schema::table('stock_take_results', function (Blueprint $table) {
            $table->decimal('damaged_qty_base', 12, 3)->default(0)->after('total_counted_base');
            $table->decimal('expired_qty_base', 12, 3)->default(0)->after('damaged_qty_base');
            $table->decimal('writeoff_value', 12, 2)->nullable()->after('variance_value');
            $table->foreignId('writeoff_ledger_id')->nullable()->after('inventory_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_results', function (Blueprint $table) {
            $table->dropColumn(['damaged_qty_base', 'expired_qty_base', 'writeoff_value', 'writeoff_ledger_id']);
        });

        Schema::table('stock_take_entries', function (Blueprint $table) {
            $table->dropColumn(['damaged_qty_base', 'expired_qty_base']);
        });
    }
};
