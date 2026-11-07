<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use Carbon\CarbonInterface;

class GlossaryQuery extends BaseAssistantQuery
{
    /**
     * @var array<string, string>
     */
    private const TERMS = [
        'cost layer' => 'An inventory tracking batch recorded at a specific purchase cost. Whenever you receive new stock, a cost layer locks in what you paid for those units so your profit margins and valuation remain accurate over time.',
        'stock cost layer' => 'An inventory tracking batch recorded at a specific purchase cost. Whenever you receive new stock, a cost layer locks in what you paid for those units so your profit margins and valuation remain accurate over time.',
        'blind count' => 'A physical stock count performed without revealing current recorded system quantities to the count participant. This prevents bias and ensures stock discrepancies and shrinkage are identified honestly.',
        'cash-up' => 'The reconciliation process comparing expected till takings against physical cash counted at the end of a shift or trading day.',
        'z-report' => 'A final daily POS register report summarizing daily sales, payment methods, taxes, and cash variances. It closes the trading session and resets register totals for the next day.',
        'fifo' => 'First-In, First-Out: an inventory accounting method where your oldest purchased stock units are treated as sold first, ensuring your profit margins reflect the actual historical cost of goods.',
    ];

    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function requiresScopeAndDates(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $term = trim((string) ($options['term'] ?? ''));
        $normalized = strtolower($term);

        if (array_key_exists($normalized, self::TERMS)) {
            return QueryResult::withData([
                'found' => true,
                'term' => $term,
                'definition' => self::TERMS[$normalized],
            ]);
        }

        return QueryResult::withData([
            'found' => false,
            'term' => $term,
            'definition' => '',
        ]);
    }
}
