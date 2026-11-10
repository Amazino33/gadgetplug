<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\AffiliateCommission;
use App\Models\OrderItemStoreAllocation;
use Carbon\CarbonInterface;

class AffiliateCommissionsQuery extends BaseAssistantQuery
{
    /** Same gate as the no vendor screen; owner-only screen. */
    public function ownerOnly(): bool
    {
        return true;
    }

    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];

        // Find orders associated with these store allocations
        $allOrderIds = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->whereIn('order_item_store_allocations.store_id', $storeIds)
            ->pluck('order_items.order_id')
            ->unique()
            ->all();

        $hasAnyCommissions = ! empty($allOrderIds) && AffiliateCommission::query()
            ->whereIn('order_id', $allOrderIds)
            ->exists();

        if (! $hasAnyCommissions) {
            return QueryResult::empty('no_commissions');
        }

        $commissions = AffiliateCommission::query()
            ->whereIn('order_id', $allOrderIds)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->get();

        $earnedNaira = (float) $commissions->where('status', '!=', 'rejected')->sum('amount');
        $pendingNaira = (float) $commissions->whereIn('status', ['pending', 'return_window'])->sum('amount');
        $paidNaira = (float) $commissions->whereIn('status', ['available', 'paid'])->sum('amount');

        return QueryResult::withData([
            ...self::naira('earned_total', $earnedNaira),
            ...self::naira('pending_total', $pendingNaira),
            ...self::naira('paid_total', $paidNaira),
        ]);
    }
}
