<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CustomerDebtsQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];

        // Sum ledger amount per customer across scoped stores
        $customerBalances = PosCustomerLedgerEntry::query()
            ->whereIn('store_id', $storeIds)
            ->groupBy('pos_customer_id')
            ->selectRaw('pos_customer_id, SUM(amount) as balance, MIN(occurred_at) as first_charge_date')
            ->havingRaw('SUM(amount) > 0')
            ->get();

        if ($customerBalances->isEmpty()) {
            return QueryResult::empty('no_debts');
        }

        $totalOwedNaira = (float) $customerBalances->sum('balance');
        $debtorCount = $customerBalances->count();
        $oldestDate = $customerBalances->min('first_charge_date');

        $top5 = $customerBalances->sortByDesc('balance')->take(5);
        $customerIds = $top5->pluck('pos_customer_id')->all();
        $customerNames = PosCustomer::whereIn('id', $customerIds)->pluck('name', 'id');

        $topDebtors = $top5->map(fn ($row) => [
            'name' => (string) ($customerNames[$row->pos_customer_id] ?? 'Unknown'),
            'amount' => (int) round(((float) $row->balance) * 100),
        ])->values()->all();

        return QueryResult::withData([
            'total_owed' => (int) round($totalOwedNaira * 100),
            'debtor_count' => $debtorCount,
            'oldest_debt_date' => $oldestDate ? Carbon::parse($oldestDate)->format('Y-m-d') : null,
            'debtors' => $topDebtors,
        ]);
    }
}
