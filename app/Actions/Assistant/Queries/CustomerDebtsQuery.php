<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\PosCustomer;
use App\Models\Store;
use App\Services\Pos\CustomerDebtService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who owes the business, how much and for how long — CustomerDebtService::
 * aged(), the debt ledger's own read-time FIFO: payments clear the oldest
 * charges first, so a payment taken at one branch does not leave another
 * branch's charge looking unpaid. Whole business (all of a vendor's stores)
 * is the vendor-wide figure the Customer Debts widget shows.
 */
class CustomerDebtsQuery extends BaseAssistantQuery
{
    /** Same gate as the Customer Debts screen. */
    public function requiredPermission(): ?string
    {
        return 'view_customer_debts';
    }

    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];
        $debts = app(CustomerDebtService::class);

        $buckets = ['current' => 0.0, '8_30' => 0.0, '31_60' => 0.0, 'over_60' => 0.0];
        /** @var Collection<int, array{outstanding: float, days_oldest: int}> $debtors keyed by customer */
        $debtors = collect();

        foreach (Store::query()->whereIn('id', $storeIds)->get(['id', 'vendor_id'])->groupBy('vendor_id') as $vendorId => $stores) {
            $all = Store::query()->where('vendor_id', $vendorId)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $wholeVendor = $stores->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $all;

            $parts = $wholeVendor
                ? [$debts->aged((int) $vendorId)]
                : $stores->map(fn (Store $s) => $debts->aged((int) $vendorId, (int) $s->id))->all();

            foreach ($parts as $part) {
                foreach ($part['buckets'] as $bucket => $amount) {
                    $buckets[$bucket] += (float) $amount;
                }

                foreach ($part['debtors'] as $d) {
                    $id = (int) $d['customer_id'];
                    $seen = $debtors->get($id, ['outstanding' => 0.0, 'days_oldest' => 0, 'oldest_at' => null]);
                    $oldest = $d['oldest_at'] ? Carbon::parse($d['oldest_at'])->toDateString() : null;
                    $debtors->put($id, [
                        'outstanding' => $seen['outstanding'] + (float) $d['outstanding'],
                        'days_oldest' => max($seen['days_oldest'], (int) $d['days_oldest']),
                        'oldest_at' => $seen['oldest_at'] === null ? $oldest : min($seen['oldest_at'], $oldest ?? $seen['oldest_at']),
                    ]);
                }
            }
        }

        if ($debtors->isEmpty()) {
            return QueryResult::empty('no_debts');
        }

        $top = $debtors->sortByDesc('outstanding')->take(5);
        $names = PosCustomer::whereIn('id', $top->keys()->all())->pluck('name', 'id');

        return QueryResult::withData([
            ...self::naira('total_owed', $debtors->sum('outstanding')),
            'debtor_count' => $debtors->count(),
            // The oldest charge still open (after payments clear the oldest first).
            'oldest_debt_date' => $debtors->pluck('oldest_at')->filter()->min(),
            'oldest_debt_days' => (int) $debtors->max('days_oldest'),
            'aging' => collect($buckets)->map(fn (float $amount, string $bucket) => ['bucket' => $bucket, ...self::naira('amount', $amount)])->values()->all(),
            'debtors' => $top->map(fn (array $d, int $id) => [
                'name' => (string) ($names[$id] ?? 'Unknown'),
                ...self::naira('amount', $d['outstanding']),
                'days_oldest' => $d['days_oldest'],
            ])->values()->all(),
        ]);
    }
}
