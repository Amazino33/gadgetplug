<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\Procurement;
use Carbon\CarbonInterface;

class SupplierOweingsQuery extends BaseAssistantQuery
{
    /** Same gate as the Supplier Payables (owner-only page) screen. */
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

        $hasAnyProcurements = Procurement::query()
            ->whereIn('store_id', $storeIds)
            ->where('status', '!=', Procurement::STATUS_VOIDED)
            ->exists();

        if (! $hasAnyProcurements) {
            return QueryResult::empty('no_procurements');
        }

        $unpaidProcurements = Procurement::query()
            ->whereIn('store_id', $storeIds)
            ->where('status', '!=', Procurement::STATUS_VOIDED)
            ->where('payment_status', '!=', 'full')
            ->get();

        $totalUnpaidNaira = 0.0;
        $dueDates = [];

        foreach ($unpaidProcurements as $procurement) {
            $unpaid = max(0.0, (float) $procurement->total_cost - (float) $procurement->amount_paid);
            $totalUnpaidNaira += $unpaid;

            if ($procurement->created_at) {
                $dueDates[] = $procurement->created_at->format('Y-m-d');
            }
        }

        return QueryResult::withData([
            ...self::naira('total_unpaid', $totalUnpaidNaira),
            'invoices_count' => $unpaidProcurements->count(),
            'due_dates' => array_values(array_unique($dueDates)),
        ]);
    }
}
