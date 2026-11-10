<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\Store;
use App\Models\VendorPayout;
use Carbon\CarbonInterface;

/**
 * Vendor Payouts Due Query.
 *
 * Despite not supporting multi-store aggregation, this query is scoped to the
 * overall vendor entity rather than a single branch store. It accepts a store_id
 * in the scope for interface consistency across all assistant queries, resolves
 * the corresponding vendor, and evaluates vendor-level payouts.
 */
class VendorPayoutsDueQuery extends BaseAssistantQuery
{
    /** Same gate as the Payouts screen. */
    public function requiredPermission(): ?string
    {
        return 'view_payouts';
    }

    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));
        $store = Store::query()->find($storeId);

        if (! $store) {
            return QueryResult::withData([
                'pending_total' => 0,
                'paid_total' => 0,
            ]);
        }

        $vendorId = (int) $store->vendor_id;

        $hasAnyPayouts = VendorPayout::query()
            ->where('vendor_id', $vendorId)
            ->exists();

        if (! $hasAnyPayouts) {
            return QueryResult::empty('no_payouts');
        }

        $payouts = VendorPayout::query()
            ->where('vendor_id', $vendorId)
            ->get();

        $pendingNaira = (float) $payouts->where('status', 'pending')->sum('amount');
        $paidNaira = (float) $payouts->where('status', 'paid')->sum('amount');

        return QueryResult::withData([
            ...self::naira('pending_total', $pendingNaira),
            ...self::naira('paid_total', $paidNaira),
        ]);
    }
}
