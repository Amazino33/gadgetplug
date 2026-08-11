<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

use App\Models\Product;
use App\Models\Vendor;
use App\Services\Reporting\ProductVelocityService;
use App\Services\Reporting\RestockAnalysisResult;

// Reads the same ProductVelocityService classification the restock report
// uses — TIER_DEAD_STOCK_CANDIDATE already means exactly "zero velocity,
// stock on hand, not explained by a stockout" (see ProductVelocityService's
// own tier logic), so nothing about "what counts as dead stock" is decided
// here a second time.
//
// FLAG: there is no dedicated dead-stock detail page yet (out of scope for
// the restock prompt that built the classifier). link is deliberately null;
// Phase 2's grid must render this card as non-clickable / "coming soon"
// rather than invent a route that doesn't exist.
class DeadStockCardProvider implements ReportCardProvider
{
    public function summarize(int $vendorId): CardSummary
    {
        $vendor = Vendor::findOrFail($vendorId);
        $settings = $vendor->restockSettings();

        $results = app(ProductVelocityService::class)->forVendor(
            vendorId: $vendorId,
            windowDays: $settings['windowDays'],
            leadTimeDays: $settings['leadTimeDays'],
            targetCoverDays: $settings['targetCoverDays'],
            safetyBufferDays: $settings['safetyBufferDays'],
        );

        $deadStock = $results->filter(fn (RestockAnalysisResult $r) => $r->isDeadStockCandidate());

        $tiedUpValue = 0.0;
        if ($deadStock->isNotEmpty()) {
            $costByProduct = Product::whereIn('id', $deadStock->keys())->pluck('cost_price', 'id');

            foreach ($deadStock as $productId => $result) {
                $tiedUpValue += $result->currentStock * (float) ($costByProduct[$productId] ?? 0);
            }
        }

        $headline = $deadStock->isEmpty()
            ? 'No dead stock candidates right now'
            : '₦' . number_format($tiedUpValue, 2) . ' tied up in ' . $deadStock->count() . ' slow-moving/dead product' . ($deadStock->count() === 1 ? '' : 's');

        return new CardSummary(
            key: 'dead_stock',
            title: 'Dead Stock',
            headline: $headline,
            actionableCount: $deadStock->count(),
            urgency: $deadStock->isEmpty() ? CardSummary::URGENCY_CALM : CardSummary::URGENCY_ATTENTION,
            link: null,
            note: 'Full dead-stock report not built yet — this is a preview of the same numbers.',
        );
    }
}
