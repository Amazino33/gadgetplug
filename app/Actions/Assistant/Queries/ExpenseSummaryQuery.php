<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\TillExpense;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ExpenseSummaryQuery extends BaseAssistantQuery
{
    /** Same gate as the Expenses screen. */
    public function requiredPermission(): ?string
    {
        return 'manage_expenses';
    }

    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));

        $row = TillExpense::query()
            ->where('store_id', $storeId)
            ->where(DB::raw('COALESCE(spent_at, created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(spent_at, created_at)'), '<=', $to)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_expenses, COUNT(*) as count')
            ->first();

        return QueryResult::withData([
            ...self::naira('total_expenses', ((float) ($row->total_expenses ?? 0))),
            'count' => (int) ($row->count ?? 0),
        ]);
    }
}
