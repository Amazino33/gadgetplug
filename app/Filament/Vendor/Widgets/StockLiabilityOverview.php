<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\StockAccountabilityEntry;
use App\Models\User;
use App\Services\StockAccountabilityLedger;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

// The headline numbers the accountability screen exists to answer: what is
// still owed, what the business has absorbed, and how much sits unattributed.
class StockLiabilityOverview extends BaseWidget
{
    protected ?string $pollingInterval = null;

    // Every figure here is money derived from cost price, so the whole widget
    // is behind the cost gate rather than blanking individual stats.
    public static function canView(): bool
    {
        return ProductForm::canSeeCostPrice();
    }

    protected function getStats(): array
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return [];
        }

        $ledger = app(StockAccountabilityLedger::class);

        // Outstanding is summed per person rather than in one query, because
        // outstandingFor() nets off reversals — and only reversals of
        // *recoverable* entries reduce a debt. Summing the amount column
        // directly would quietly count written-off losses as money owed.
        $staffIds = StockAccountabilityEntry::query()
            ->where('vendor_id', $vendor->id)
            ->where('disposition', 'recoverable')
            ->whereNotNull('storekeeper_id')
            ->distinct()
            ->pluck('storekeeper_id');

        $outstanding = $staffIds
            ->map(fn (int $id): float => $ledger->outstandingFor($id, $vendor->id))
            ->filter(fn (float $amount): bool => $amount > 0);

        $writtenOff = $ledger->writtenOffTotal($vendor->id);

        $unattributed = StockAccountabilityEntry::query()
            ->where('vendor_id', $vendor->id)
            ->whereNull('storekeeper_id')
            ->whereIn('disposition', ['written_off', 'recoverable'])
            ->sum('amount');

        return [
            Stat::make('Outstanding from staff', '₦'.number_format($outstanding->sum(), 2))
                ->description($outstanding->count() === 1
                    ? '1 person currently owes'
                    : $outstanding->count().' people currently owe')
                ->descriptionIcon('heroicon-m-user-group')
                ->color($outstanding->sum() > 0 ? 'danger' : 'success'),

            Stat::make('Written off', '₦'.number_format($writtenOff, 2))
                ->description('Absorbed as business loss')
                ->descriptionIcon('heroicon-m-scissors')
                ->color('warning'),

            Stat::make('Unattributed', '₦'.number_format((float) $unattributed, 2))
                ->description('Recorded against the store, nobody named')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('gray'),
        ];
    }
}
