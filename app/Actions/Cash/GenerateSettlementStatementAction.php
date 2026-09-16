<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\PhysicalStockCount;
use App\Models\Store;
use App\Models\StoreSettlementStatement;
use App\Models\User;
use App\Services\Cash\StoreReconciliation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Freeze the figures for a branch and a period, and name who froze them.
 *
 * Generating a statement is not a period close — nothing is locked, and a
 * correction dated inside the period can still land afterwards. It is a
 * photograph, taken because a conversation is about to happen and both people
 * need to be looking at the same numbers a week later.
 */
class GenerateSettlementStatementAction
{
    public function __construct(private readonly StoreReconciliation $reconciliation) {}

    public function execute(
        User $generatedBy,
        Store $store,
        CarbonInterface $from,
        CarbonInterface $to,
    ): StoreSettlementStatement {
        return DB::transaction(function () use ($generatedBy, $store, $from, $to) {
            $figures = $this->reconciliation->forStore($store, $from, $to);

            $figures['stock'] = $this->stock($store, $to);

            return StoreSettlementStatement::create([
                'vendor_id'      => $store->vendor_id,
                'store_id'       => $store->id,
                'period_start'   => $from,
                'period_end'     => $to,
                'generated_by'   => $generatedBy->id,
                'generated_at'   => now(),
                'payload'        => $figures,

                'expected_cash'  => $figures['cash']['expected'],
                'confirmed_cash' => $figures['cash']['confirmed'],
                'true_shortage'  => $figures['outstanding']['true_shortage'],
            ]);
        });
    }

    /**
     * The count covering this period, if one was taken.
     *
     * Absent is a meaningful answer and is recorded as such: a settlement with
     * no stock count behind it has only checked the money, and the statement
     * should say so rather than leave the section blank and looking clean.
     */
    private function stock(Store $store, CarbonInterface $to): array
    {
        $count = PhysicalStockCount::query()
            ->where('store_id', $store->id)
            ->where('period_end', '<=', $to)
            ->with('lines')
            ->latest('period_end')
            ->first();

        if (! $count) {
            return ['counted' => false];
        }

        return [
            'counted'     => true,
            'count_id'    => $count->id,
            'counted_at'  => $count->counted_at?->toDateTimeString(),
            'counted_by'  => $count->countedBy?->name,
            'variance'    => $count->variance(),
            // Frozen with the rest: which products were short, and by how much.
            // A total tells the person signing there is a problem; this tells
            // them which shelf it is on.
            'discrepancies' => $count->discrepancies()->all(),
        ];
    }
}
