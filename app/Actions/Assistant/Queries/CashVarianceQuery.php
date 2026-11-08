<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\PosZReport;
use Carbon\CarbonInterface;

class CashVarianceQuery extends BaseAssistantQuery
{
    /** Same gate as the Store Settlement screen. */
    public function requiredPermission(): ?string
    {
        return 'view_store_settlement';
    }

    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));

        $hasAnyCashUpsEver = PosZReport::query()
            ->whereHas('session', fn ($q) => $q->where('store_id', $storeId))
            ->exists();

        if (! $hasAnyCashUpsEver) {
            return QueryResult::empty('no_cash_ups_yet');
        }

        $reports = PosZReport::query()
            ->whereHas('session', fn ($q) => $q->where('store_id', $storeId))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at', 'asc')
            ->get();

        $cashUps = [];
        $totalExpectedNaira = 0.0;
        $totalCountedNaira = 0.0;
        $totalVarianceNaira = 0.0;

        foreach ($reports as $report) {
            $expected = (float) $report->cash_expected;
            $counted = (float) $report->cash_counted;
            $variance = (float) $report->cash_variance;

            $totalExpectedNaira += $expected;
            $totalCountedNaira += $counted;
            $totalVarianceNaira += $variance;

            $cashUps[] = [
                'date' => $report->created_at->format('Y-m-d H:i:s'),
                ...self::naira('expected', $expected),
                ...self::naira('counted', $counted),
                ...self::naira('variance', $variance),
            ];
        }

        return QueryResult::withData([
            'cash_ups' => $cashUps,
            ...self::naira('total_expected', $totalExpectedNaira),
            ...self::naira('total_counted', $totalCountedNaira),
            ...self::naira('total_variance', $totalVarianceNaira),
        ]);
    }
}
