<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Store;
use App\Services\ActiveStore;
use App\Services\Inventory\StoreStockMetrics;
use App\Services\Reporting\StoreSalesQuery;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

// One screen answering "which branch carries the most capital, and which needs
// attention" — the payoff of the whole multi-store build.
//
// Owner-facing by design: comparing branches against each other is a business
// question, not a shop-floor one, and a storekeeper has no use for another
// branch's capital position. The gate is canAccess() rather than filtering the
// table, so a member is never shown a page that would look broken with one row
// on it. The data layer is scoped independently regardless — see rows().
class StoresOverview extends Page
{
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-building-office-2';
    protected static string|null|UnitEnum   $navigationGroup = 'Store';
    protected static ?string $navigationLabel = 'All Stores';
    protected static ?string $title           = 'All Stores';
    protected static ?int    $navigationSort  = 1;
    protected string $view = 'filament.vendor.pages.stores-overview';

    // Days of sales the comparison covers. Fixed rather than configurable:
    // this phase is about making the comparison exist, and a period picker is
    // a separate decision about what owners actually want to compare.
    private const PERIOD_DAYS = 7;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user && ($user->isSuperAdmin() || $vendor->isOwner($user));
    }

    /** @return Collection<int, Store> */
    public function stores(): Collection
    {
        // Scoped through the same accessible-stores rule the switcher and the
        // grid use, not through "every store this vendor has". canAccess()
        // already limits this page to owners, so today the two are the same —
        // but if that gate ever loosens, this cannot start leaking branches a
        // viewer has no claim on.
        return ActiveStore::accessibleFor(filament()->getTenant(), auth()->user());
    }

    public function canSeeCostValue(): bool
    {
        return ProductForm::canSeeCostPrice();
    }

    public function periodLabel(): string
    {
        return 'Last '.self::PERIOD_DAYS.' days';
    }

    /**
     * One row per branch, plus the totals across them.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function comparison(): array
    {
        $vendorId = filament()->getTenant()->id;
        $stores = $this->stores();
        $metrics = StoreStockMetrics::forStores($stores->pluck('id'));

        $from = CarbonImmutable::now()->subDays(self::PERIOD_DAYS)->startOfDay();
        $to = CarbonImmutable::now()->endOfDay();

        $rows = $stores->map(function (Store $store) use ($vendorId, $metrics, $from, $to) {
            $m = $metrics[$store->id] ?? StoreStockMetrics::empty();
            $sales = StoreSalesQuery::totals($vendorId, $store->id, $from, $to);

            return [
                'store'              => $store,
                'product_count'      => $m->product_count,
                'units'              => $m->units,
                'cost_value'         => $m->cost_value,
                'retail_value'       => $m->retail_value,
                'low_stock_count'    => $m->low_stock_count,
                'missing_cost_count' => $m->missing_cost_count,
                'sales_revenue'      => $sales['revenue'],
                'sales_units'        => $sales['units'],
            ];
        })
            // Heaviest capital first: the branch holding the most stock is the
            // one whose problems cost the most.
            ->sortByDesc('retail_value')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'product_count'      => array_sum(array_column($rows, 'product_count')),
                'units'              => array_sum(array_column($rows, 'units')),
                'cost_value'         => array_sum(array_column($rows, 'cost_value')),
                'retail_value'       => array_sum(array_column($rows, 'retail_value')),
                'low_stock_count'    => array_sum(array_column($rows, 'low_stock_count')),
                'missing_cost_count' => array_sum(array_column($rows, 'missing_cost_count')),
                'sales_revenue'      => array_sum(array_column($rows, 'sales_revenue')),
                'sales_units'        => array_sum(array_column($rows, 'sales_units')),
            ],
        ];
    }

    // Per-branch sales come from the allocations, and a line that reached
    // revenue recognition without one belongs to no branch. The branches can
    // therefore sum to slightly less than the vendor-wide figure, and saying so
    // is better than letting the owner find the discrepancy themselves.
    public function unattributedSales(): float
    {
        $vendorId = filament()->getTenant()->id;
        $from = CarbonImmutable::now()->subDays(self::PERIOD_DAYS)->startOfDay();
        $to = CarbonImmutable::now()->endOfDay();

        $vendorWide = StoreSalesQuery::totals($vendorId, null, $from, $to)['revenue'];
        $perStore = collect($this->comparison()['rows'])->sum('sales_revenue');

        return max(0.0, $vendorWide - $perStore);
    }
}
