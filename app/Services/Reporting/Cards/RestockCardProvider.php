<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

use App\Filament\Vendor\Pages\RestockReport;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Reporting\ProductVelocityService;
use App\Services\Reporting\RestockAnalysisResult;

// Reads ProductVelocityService directly — the same classifier the restock
// report itself renders, using the vendor's own configured settings (via
// Vendor::restockSettings()) so this card and that report can never disagree
// about which products are urgent.
class RestockCardProvider implements ReportCardProvider
{
    private const MAX_NAMES_SHOWN = 2;

    public function summarize(int $vendorId, ?int $storeId = null): CardSummary
    {
        $vendor = Vendor::findOrFail($vendorId);
        $settings = $vendor->restockSettings();

        $results = app(ProductVelocityService::class)->forVendor(
            vendorId: $vendorId,
            windowDays: $settings['windowDays'],
            leadTimeDays: $settings['leadTimeDays'],
            targetCoverDays: $settings['targetCoverDays'],
            safetyBufferDays: $settings['safetyBufferDays'],
            // With a store active, "what needs restocking" means that branch's
            // shelves — a branch can be empty while the vendor has plenty.
            storeId: $storeId,
        );

        $needsRestock = $results->filter(fn (RestockAnalysisResult $r) => $r->needsRestock());
        $urgent = $needsRestock->filter(fn (RestockAnalysisResult $r) => $r->tier === RestockAnalysisResult::TIER_URGENT);

        $headline = $needsRestock->isEmpty()
            ? 'Nothing needs restocking right now'
            : $needsRestock->count() . ' product' . ($needsRestock->count() === 1 ? ' needs' : 's need') . ' restocking, '
                . $urgent->count() . ' urgent';

        if ($urgent->isNotEmpty()) {
            $names = Product::whereIn('id', $urgent->keys())
                ->orderBy('name')
                ->limit(self::MAX_NAMES_SHOWN + 1)
                ->pluck('name');

            $shown = $names->take(self::MAX_NAMES_SHOWN);
            $extra = $urgent->count() - $shown->count();

            $headline .= ': ' . $shown->implode(', ') . ($extra > 0 ? " +{$extra} more" : '');
        }

        return new CardSummary(
            key: 'restock',
            title: 'Restock',
            headline: $headline,
            actionableCount: $needsRestock->count(),
            urgency: match (true) {
                $urgent->isNotEmpty() => CardSummary::URGENCY_URGENT,
                $needsRestock->isNotEmpty() => CardSummary::URGENCY_ATTENTION,
                default => CardSummary::URGENCY_CALM,
            },
            link: RestockReport::getUrl(panel: 'vendor', tenant: $vendor),
        );
    }
}
