<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stock itself: what each branch holds, the vendor-wide mirror of it, the cost
// batches that value it, and the ledger of every movement.
//
// DECIMAL(12,3), signed. The UNSIGNED some of these had is dropped on purpose:
// MySQL deprecates it for DECIMAL, and validation already keeps negatives out
// where they do not belong. Every ->change() restates the column's default and
// nullability, because Laravel 12 drops whatever a change() leaves out.
//
// Whole numbers come through exactly — 7 becomes 7.000 — so nothing a shop
// that never sells halves has stored is altered by this.
return new class extends Migration
{
    /** table => [column => [nullable, default, original type]] */
    private const COLUMNS = [
        'product_store_stock' => [
            'quantity' => [false, 0, 'integer'],
            'reserved' => [false, 0, 'integer'],
        ],
        'products' => [
            'stock_quantity' => [false, 0, 'integer'],
            'reserved_stock' => [false, 0, 'unsignedInteger'],
        ],
        'stock_cost_layers' => [
            'quantity_received' => [false, null, 'integer'],
            'quantity_remaining' => [false, null, 'integer'],
        ],
        'inventory_ledgers' => [
            'quantity_change' => [false, null, 'integer'],
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column => [$nullable, $default]) {
                    $definition = $table->decimal($column, 12, 3)->nullable($nullable);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Refused outright rather than rounding: turning 19.5 crates back into
        // an integer column would quietly lose half a crate of somebody's stock.
        foreach (self::COLUMNS as $tableName => $columns) {
            foreach (array_keys($columns) as $column) {
                if (DB::table($tableName)->whereRaw("ROUND({$column}, 0) <> {$column}")->exists()) {
                    throw new RuntimeException("{$tableName}.{$column} holds fractional quantities; they must be made whole before this migration can be rolled back.");
                }
            }
        }

        foreach (self::COLUMNS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column => [$nullable, $default, $type]) {
                    $definition = $table->{$type}($column)->nullable($nullable);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                }
            });
        }
    }
};
