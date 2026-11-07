<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\PosSaleItem;
use App\Models\ProductStoreStock;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Products sold below zero, and the sales whose cost had to be estimated.
 *
 * Only shops that allow selling below zero (or products set to) ever have
 * rows here. Each one is goods that left the shop before the records said
 * they had arrived — settled by a delivery or a stock count.
 */
class NegativeStockReport extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Negative Stock';

    protected static ?string $title = 'Negative Stock';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.vendor.pages.negative-stock-report';

    public function getViewData(): array
    {
        $vendorId = filament()->getTenant()->id;

        // Last time each product sold at each branch.
        $lastSales = DB::table('pos_sale_items')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->groupBy('pos_sale_items.product_id', 'pos_sales.store_id')
            ->selectRaw('pos_sale_items.product_id, pos_sales.store_id, MAX(COALESCE(pos_sales.completed_at, pos_sales.created_at)) as last_sold_at');

        $rows = ProductStoreStock::query()
            ->join('products', 'products.id', '=', 'product_store_stock.product_id')
            ->leftJoin('stores', 'stores.id', '=', 'product_store_stock.store_id')
            ->leftJoinSub($lastSales, 'last_sales', fn ($join) => $join
                ->on('last_sales.product_id', '=', 'product_store_stock.product_id')
                ->on('last_sales.store_id', '=', 'product_store_stock.store_id'))
            ->where('products.vendor_id', $vendorId)
            ->where('product_store_stock.quantity', '<', 0)
            ->orderBy('product_store_stock.quantity')
            ->get([
                'products.id as product_id',
                'products.name',
                'products.sku',
                'products.base_unit',
                'stores.name as store_name',
                'product_store_stock.quantity',
                'last_sales.last_sold_at',
            ]);

        $estimatedCounts = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sale_items.cost_estimated', true)
            ->groupBy('pos_sale_items.product_id')
            ->selectRaw('pos_sale_items.product_id, COUNT(*) as lines')
            ->pluck('lines', 'product_id');

        $estimatedSales = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sale_items.cost_estimated', true)
            ->orderByDesc('pos_sales.id')
            ->limit(50)
            ->get([
                'pos_sales.reference',
                'pos_sales.completed_at',
                'pos_sale_items.product_name',
                'pos_sale_items.quantity',
                'pos_sale_items.unit_qty',
                'pos_sale_items.unit_name',
                'pos_sale_items.total',
                'pos_sale_items.cost_total',
            ]);

        return [
            'rows' => $rows,
            'estimatedCounts' => $estimatedCounts,
            'estimatedSales' => $estimatedSales,
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_inventory_reports')
        );
    }
}
