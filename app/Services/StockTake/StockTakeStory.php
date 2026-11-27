<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\InventoryLedger;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\User;
use App\Services\Inventory\StockAtTime;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The story behind one count, for the manager deciding it: what the books
 * said when the counter counted, and every movement that got them there —
 * who sold what at the till, what came in, what was moved — then what
 * happened after the count (which the count is never compared with).
 *
 *   Start of the day 25 · 9:02 sold 2 (Chika) · 9:40 sold 3 (Emeka)
 *   → 10:42 Ada counted 18, the books said 20: 2 missing.
 *
 * The day is the shop's day (Lagos). Quantities only, no money: anyone who
 * may see the result may see this.
 */
final class StockTakeStory
{
    public const TZ = 'Africa/Lagos';

    /** More than this many movements before the count: the earliest are left out of the list (never out of the figures). */
    public const MAX_ROWS = 150;

    /** What each kind of stock movement is called on screen. */
    public const LABELS = [
        'pos_sale'             => 'Sold at the till',
        'pos_void'             => 'Till sale cancelled',
        'pos_return'           => 'Returned at the till',
        'online_sale'          => 'Sold online',
        'online_return'        => 'Returned (online order)',
        'dispatched'           => 'Sent out (online order)',
        'refund'               => 'Refunded',
        'restock'              => 'Received (purchase)',
        'store_transfer'       => 'Moved between branches',
        'stock_adjustment'     => 'Stock adjusted',
        'audit_correction'     => 'Count correction',
        'count_adjustment'     => 'Stock take correction',
        'picking_out'          => 'Given out on credit',
        'picking_return'       => 'Brought back',
    ];

    /**
     * @return array{
     *   product: string, base_unit: string, counted_at: CarbonInterface, counted_by: string,
     *   counts: list<array{aisle: string, qty: int|float, at: CarbonInterface, by: string}>,
     *   counted: int|float, damaged: int|float, expired: int|float, present: int|float,
     *   day_start: CarbonInterface, opening: int|float, books: int|float, difference: int|float,
     *   before: list<array>, hidden_before: int, after: list<array>, now: int|float,
     *   sellers: list<array{name: string, qty: int|float, sales: int}>, used_at_approval: int|float|null
     * }
     */
    public function for(StockTakeResult $result): array
    {
        $result->loadMissing(['product:id,name,base_unit', 'task.assignee:id,name']);

        $productId = (int) $result->product_id;
        $storeId   = (int) $result->store_id;
        $countedAt = CarbonImmutable::parse($result->counted_at);
        $dayStart  = $countedAt->setTimezone(self::TZ)->startOfDay()->utc();

        $opening = StockAtTime::at($productId, $storeId, $dayStart);
        $books   = StockAtTime::at($productId, $storeId, $countedAt);

        $movements = InventoryLedger::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->whereNotIn('transaction_type', InventoryLedger::RESERVATION_ONLY_TYPES)
            ->where('occurred_at', '>', $dayStart)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'transaction_type', 'quantity_change', 'user_id', 'reference', 'description', 'occurred_at']);

        $names = User::query()->whereIn('id', $movements->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        $before  = [];
        $after   = [];
        $running = $opening;

        foreach ($movements as $m) {
            $running = Quantity::add($running, $m->quantity_change);
            $row = [
                'at'      => $m->occurred_at,
                'what'    => $this->label($m),
                'type'    => $m->transaction_type,
                'qty'     => $m->quantity_change,
                'by'      => $m->user_id ? ($names[$m->user_id] ?? 'Someone') : null,
                'ref'     => $m->reference,
                'balance' => $running,
            ];

            if ($m->occurred_at->lessThanOrEqualTo($countedAt)) {
                $before[] = $row;
            } else {
                $after[] = $row;
            }
        }

        // Who sold it before the count: the line a manager reads first.
        $sellers = collect($before)
            ->where('type', 'pos_sale')
            ->groupBy(fn ($r) => $r['by'] ?? 'Unknown till')
            ->map(fn (Collection $rows, $name) => [
                'name'  => (string) $name,
                'qty'   => Quantity::of(abs((float) $rows->sum('qty'))),
                'sales' => $rows->count(),
            ])
            ->sortByDesc('qty')
            ->values()
            ->all();

        $hidden = max(0, count($before) - self::MAX_ROWS);

        $present = Quantity::add($result->total_counted_base, $result->damaged_qty_base ?? 0, $result->expired_qty_base ?? 0);

        return [
            'product'          => $result->product?->name ?? "#{$productId}",
            'base_unit'        => $result->product?->base_unit ?: 'Piece',
            'counted_at'       => $countedAt,
            'counted_by'       => $result->task?->assignee?->name ?? 'The counter',
            'counts'           => $this->counts($result),
            'counted'          => $result->total_counted_base,
            'damaged'          => $result->damaged_qty_base ?? 0,
            'expired'          => $result->expired_qty_base ?? 0,
            'present'          => $present,
            'day_start'        => $dayStart,
            'opening'          => $opening,
            'books'            => $books,
            'difference'       => Quantity::sub($present, $books),
            'before'           => array_slice($before, $hidden),
            'hidden_before'    => $hidden,
            'after'            => $after,
            'now'              => $running,
            'sellers'          => $sellers,
            // Approved before a late till sale arrived: the figure used then.
            'used_at_approval' => $result->applied_system_qty !== null && (float) $result->applied_system_qty != (float) $books
                ? $result->applied_system_qty
                : null,
        ];
    }

    /** What a movement is called; the automatic late-sale correction says so. */
    private function label(InventoryLedger $m): string
    {
        if ($m->transaction_type === 'count_adjustment' && str_contains((string) $m->description, 'late till sale')) {
            return 'Corrected for a late till sale';
        }

        return self::LABELS[$m->transaction_type] ?? ucfirst(str_replace('_', ' ', (string) $m->transaction_type));
    }

    /** Where and when it was counted: one line per aisle. */
    private function counts(StockTakeResult $result): array
    {
        $entries = StockTakeEntry::query()
            ->where('stock_take_task_id', $result->stock_take_task_id)
            ->where('product_id', $result->product_id)
            ->with(['aisle'])
            ->orderBy('counted_at')
            ->get();

        $names = User::query()->whereIn('id', $entries->pluck('counted_by')->filter()->unique())->pluck('name', 'id');

        return $entries->map(fn (StockTakeEntry $e) => [
            'aisle'   => $e->aisle?->is_quick ? 'No aisle' : ($e->aisle?->label() ?? '—'),
            'qty'     => $e->counted_qty_base,
            'damaged' => $e->damaged_qty_base ?? 0,
            'expired' => $e->expired_qty_base ?? 0,
            'at'      => $e->counted_at,
            'by'      => $names[$e->counted_by] ?? 'The counter',
        ])->all();
    }
}
