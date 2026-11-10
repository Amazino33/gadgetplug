<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Services\Assistant\AssistantScope;
use App\Services\Pos\CashierSalesHistory;
use Carbon\CarbonInterface;

/**
 * "How much have I sold today?": the asker's own till sales, the figures the
 * till's My Sales screen shows (CashierSalesHistory::summary(), same Lagos
 * day bounds), across every branch of the business as that screen does.
 *
 * Always the person asking: the cashier is the signed-in user from the
 * access scope, never anything the model sends, so no one can ask about a
 * colleague's sales. No permission beyond using the assistant: everyone may
 * see what they rang themselves (owner decision, 2026-11-08). Shop totals
 * stay behind view_inventory_reports, in sales_summary.
 */
class MySalesQuery extends BaseAssistantQuery
{
    public function requiredPermission(): ?string
    {
        return null;
    }

    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $access = AssistantScope::current();
        $vendorId = $access?->vendor?->id;
        $userId = $access?->user->id;

        if ($vendorId === null || $userId === null) {
            return QueryResult::empty('not_configured');
        }

        $s = app(CashierSalesHistory::class)->summary((int) $vendorId, (int) $userId, $from, $to);

        return QueryResult::withData([
            'whose' => 'your own sales (rung by you at the till, all branches)',
            'sales_count' => $s['sales_count'],
            ...self::naira('revenue', $s['revenue'] ?? 0),
            ...self::naira('collected', $s['collected']),
            ...self::naira('cash', $s['tenders']['cash']),
            ...self::naira('card', $s['tenders']['card']),
            ...self::naira('transfer', $s['tenders']['bank_transfer']),
            ...self::naira('on_credit', $s['credit']),
            'refunds_count' => (int) ($s['refunds']['count'] ?? 0),
            ...self::naira('refunds', $s['refunds']['total'] ?? 0),
            'voided_count' => $s['voided']['count'],
        ]);
    }
}
