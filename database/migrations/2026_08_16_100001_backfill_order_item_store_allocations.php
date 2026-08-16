<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Every historical order line becomes a single-store allocation at its
// vendor's default store, for its full quantity — which is where that stock
// actually came from, since until now the actions only ever moved the default
// store's row.
//
// Query-builder writes, like the earlier stock backfills: no model events, so
// nothing recomputes a mirror mid-migration. Idempotent per (line, store).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $defaultStoreByVendor = DB::table('stores')
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id', 'vendor_id');

        DB::table('order_items')
            ->select('id', 'vendor_id', 'quantity')
            ->orderBy('id')
            ->chunk(500, function ($items) use ($defaultStoreByVendor, $now) {
                $rows = [];

                foreach ($items as $item) {
                    $storeId = $defaultStoreByVendor[$item->vendor_id] ?? null;

                    if ($storeId === null) {
                        throw new RuntimeException(
                            "Order item allocation backfill aborted: vendor {$item->vendor_id} has no default store (order item {$item->id})."
                        );
                    }

                    $exists = DB::table('order_item_store_allocations')
                        ->where('order_item_id', $item->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $rows[] = [
                        'order_item_id' => $item->id,
                        'store_id'      => $storeId,
                        'quantity'      => (int) $item->quantity,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('order_item_store_allocations')->insert($rows);
                }
            });

        $this->assertEveryLineIsFullyAllocated();
    }

    // Dropped wholesale by the create-table migration above it; emptying the
    // table here would discard allocations later phases may have split by hand.
    public function down(): void
    {
        //
    }

    // Per line, not in aggregate: two lines allocated wrongly in opposite
    // directions would cancel out in a global sum and pass unnoticed.
    private function assertEveryLineIsFullyAllocated(): void
    {
        $wrong = DB::table('order_items as oi')
            ->leftJoin('order_item_store_allocations as a', 'a.order_item_id', '=', 'oi.id')
            ->groupBy('oi.id', 'oi.quantity')
            ->havingRaw('COALESCE(SUM(a.quantity), 0) <> oi.quantity')
            ->select('oi.id')
            ->pluck('oi.id');

        if ($wrong->isNotEmpty()) {
            throw new RuntimeException(
                'Order item allocation backfill aborted: allocations do not sum to the line quantity for order item ids '
                .$wrong->take(20)->implode(', ')
                .($wrong->count() > 20 ? ' (and '.($wrong->count() - 20).' more)' : '')
            );
        }
    }
};
