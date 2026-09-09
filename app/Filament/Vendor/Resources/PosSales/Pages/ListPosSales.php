<?php

namespace App\Filament\Vendor\Resources\PosSales\Pages;

use App\Filament\Vendor\Resources\PosSales\PosSaleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class ListPosSales extends ListRecords
{
    protected static string $resource = PosSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * One tab per branch, for a vendor that has more than one.
     *
     * A vendor with a single store gets no tabs at all: a row of tabs where
     * every tab shows the same thing is worse than none, and this page has
     * worked without them until now.
     *
     * The badge is that branch's takings for whatever date range the filter
     * has in force — daily by default — so the owner can compare branches by
     * glancing at the tabs rather than by opening each one in turn.
     */
    public function getTabs(): array
    {
        $stores = PosSaleResource::storeOptions();

        if (count($stores) <= 1) {
            return [];
        }

        $tabs = [
            'all' => Tab::make('All Stores')
                ->badge($this->takings(null))
                ->badgeColor('gray'),
        ];

        foreach ($stores as $id => $name) {
            $tabs['store-'.$id] = Tab::make($name)
                ->badge($this->takings((int) $id))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('pos_sales.store_id', $id));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    /**
     * What one branch took over the period the filter is showing.
     *
     * Voided sales are left out — they are money that never stayed in the
     * drawer, and counting them would flatter a branch that rang a mistake.
     * Refunds are NOT deducted here: a refund is recorded against the sale's
     * own status rather than as a negative row, so this is takings rung, which
     * is the figure the tab is claiming to show.
     *
     * Deliberately independent of the table's own summariser, which totals the
     * rows on screen including voided ones. They answer different questions and
     * the column is labelled for its own.
     */
    private function takings(?int $storeId): string
    {
        [$from, $until] = $this->activePeriod();

        $total = \App\Models\PosSale::query()
            ->where('pos_sales.vendor_id', filament()->getTenant()->id)
            ->where('pos_sales.status', '!=', 'voided')
            ->when($storeId, fn (Builder $q) => $q->where('pos_sales.store_id', $storeId))
            ->when($from, fn (Builder $q) => $q->whereRaw(
                'COALESCE(pos_sales.completed_at, pos_sales.created_at) >= ?',
                [$from],
            ))
            ->when($until, fn (Builder $q) => $q->whereRaw(
                'COALESCE(pos_sales.completed_at, pos_sales.created_at) <= ?',
                [$until],
            ))
            ->sum('pos_sales.total');

        // Abbreviated because a tab is a few characters wide: ₦904k, not
        // ₦904,000.00 wrapping onto a second line.
        return '₦'.Number::abbreviate((float) $total, maxPrecision: 1);
    }

    /**
     * The date range the table is currently filtered to.
     *
     * Read from the live filter state rather than assumed to be today, so
     * widening the range moves the badges with it. Filters are not yet
     * hydrated on the very first render, and the null pair that comes back
     * then simply means "no date bound" — the same thing the filter itself
     * would do.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function activePeriod(): array
    {
        $state = $this->getTableFilterState('period') ?? [];

        $from = ($state['from'] ?? null) ? Carbon::parse($state['from'])->startOfDay() : null;
        $until = ($state['until'] ?? null) ? Carbon::parse($state['until'])->endOfDay() : null;

        return [$from, $until];
    }
}
