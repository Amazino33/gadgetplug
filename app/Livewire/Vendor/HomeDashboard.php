<?php

namespace App\Livewire\Vendor;

use App\Services\ActiveStore;
use App\Services\Reporting\SalesReportService;
use App\Models\Product;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class HomeDashboard extends Component
{
    public $storeId;

    public function mount()
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();
        
        if ($vendor && $user) {
            $this->storeId = ActiveStore::get($vendor, $user)?->id;
        }
    }

    public function render()
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (! $vendor || ! $user) {
            return view('livewire.vendor.home-dashboard', [
                'tiles' => [],
                'alerts' => [],
            ]);
        }

        $storeId = $this->storeId;

        // Stat strip figures
        $reports = app(SalesReportService::class);
        $today = Carbon::today('Africa/Lagos');
        
        $summary = $reports->summary($vendor->id, $today, $today, $storeId);
        
        // Low stock count scoped to active store
        // We evaluate this by checking which products are low stock in the active store.
        $productsQuery = Product::where('vendor_id', $vendor->id)
            ->where('status', 'published');
            
        // Because low stock threshold logic is dynamic in the model methods, we'll fetch products and count.
        // For performance, we can just fetch all products (or only those in stock) and count using isStoreLowStock()
        // Wait, loading all products could be heavy.
        // Store quantities are loaded by ProductResource via leftJoin.
        // Let's do a basic query to count low stock.
        // In this LEAN v1, we can just use the accessor or a simple query.
        $lowStockCount = 0;
        
        if ($storeId) {
            $lowStockCount = Product::where('vendor_id', $vendor->id)
                ->where('status', 'published')
                ->where('low_stock_threshold', '>', 0)
                ->leftJoin('product_store_stock', function ($join) use ($storeId) {
                    $join->on('products.id', '=', 'product_store_stock.product_id')
                         ->where('product_store_stock.store_id', '=', $storeId);
                })
                ->whereRaw('COALESCE(product_store_stock.quantity, products.stock_quantity) - COALESCE(product_store_stock.reserved, products.reserved_stock) > 0')
                ->whereRaw('COALESCE(product_store_stock.quantity, products.stock_quantity) - COALESCE(product_store_stock.reserved, products.reserved_stock) < products.low_stock_threshold')
                ->count();
        } else {
            // Whole business low stock
            $lowStockCount = Product::where('vendor_id', $vendor->id)
                ->where('status', 'published')
                ->where('low_stock_threshold', '>', 0)
                ->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) > 0')
                ->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) < low_stock_threshold')
                ->count();
        }

        // New orders (pending, confirmed)
        $newOrdersCount = Order::whereHas('items', function ($q) use ($vendor, $storeId) {
                $q->where('vendor_id', $vendor->id);
                if ($storeId) {
                    $q->whereHas('storeAllocations', function ($a) use ($storeId) {
                        $a->where('store_id', $storeId);
                    });
                }
            })
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();

        $isOwner = $user->isSuperAdmin() || $vendor->isOwner($user);

        // Build Tile Registry
        $tiles = collect([
            [
                'id' => 'record_sale',
                'label' => 'Record Sale',
                'icon' => 'heroicon-o-computer-desktop',
                'route' => url('/pos/' . $vendor->slug),
                'permission' => 'access_pos',
                'owner_prominence' => 'secondary',
                'staff_prominence' => 'hero',
                'color' => 'bg-amber-500 text-white',
            ],
            [
                'id' => 'today_sales',
                'label' => "Today's Sales",
                'icon' => 'heroicon-o-banknotes',
                'route' => null, // Just a glance card
                'value' => '₦' . number_format($summary['revenue']),
                'subtext' => 'Profit: ₦' . number_format($summary['profit']),
                'permission' => 'view_inventory_reports',
                'owner_prominence' => 'hidden',
                'staff_prominence' => 'secondary',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
            [
                'id' => 'view_sales',
                'label' => 'View Sales',
                'icon' => 'heroicon-o-presentation-chart-line',
                'route' => \App\Filament\Vendor\Pages\SalesReport::getUrl(),
                'permission' => 'view_inventory_reports',
                'owner_prominence' => 'hero',
                'staff_prominence' => 'hidden',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
            [
                'id' => 'customer_debts',
                'label' => 'Customer Debts',
                'icon' => 'heroicon-o-users',
                'route' => \App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource::getUrl('index'),
                'permission' => 'view_customer_debts',
                'owner_prominence' => 'secondary',
                'staff_prominence' => 'secondary',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
            [
                'id' => 'new_orders',
                'label' => 'New Orders',
                'icon' => 'heroicon-o-shopping-bag',
                'route' => \App\Filament\Vendor\Resources\Orders\OrderResource::getUrl('index'),
                'value' => $newOrdersCount,
                'permission' => 'view_any_order_items',
                'owner_prominence' => 'secondary',
                'staff_prominence' => 'secondary',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
            [
                'id' => 'change_price',
                'label' => 'Change Price',
                'icon' => 'heroicon-o-currency-dollar',
                'route' => \App\Filament\Vendor\Pages\ChangePrice::getUrl(),
                'permission' => 'edit_products',
                'owner_prominence' => 'hero',
                'staff_prominence' => 'hidden',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
            [
                'id' => 'add_product',
                'label' => 'Add Product',
                'icon' => 'heroicon-o-plus-circle',
                'route' => \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('create'),
                'permission' => 'create_products',
                'owner_prominence' => 'hero',
                'staff_prominence' => 'hidden',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
            [
                'id' => 'add_stock',
                'label' => 'Add Stock',
                'icon' => 'heroicon-o-inbox-arrow-down',
                'route' => route('procurement.create'),
                'permission' => 'manage_procurement',
                'owner_prominence' => 'hero',
                'staff_prominence' => 'hidden',
                'color' => 'bg-white text-gray-900 border border-gray-200',
            ],
        ])
        ->map(function ($tile) use ($isOwner) {
            $tile['prominence'] = $isOwner ? $tile['owner_prominence'] : $tile['staff_prominence'];
            $tile['is_hero'] = $tile['prominence'] === 'hero';
            return $tile;
        })
        ->filter(function ($tile) use ($vendor, $user, $isOwner) {
            if ($tile['prominence'] === 'hidden') {
                return false;
            }
            if ($tile['id'] === 'new_orders' && !$vendor->online_sales_enabled) {
                return false;
            }
            if ($isOwner) {
                return true;
            }
            return $user->hasVendorPermission($vendor->id, $tile['permission']);
        });

        // Alerts
        $alerts = [];
        if ($lowStockCount > 0 && ($user->isSuperAdmin() || $vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, 'manage_inventory'))) {
            $alerts[] = [
                'id' => 'low_stock',
                'label' => "{$lowStockCount} items running low on stock",
                'icon' => 'heroicon-s-exclamation-triangle',
                'color' => 'text-amber-600 bg-amber-50',
                'route' => \App\Filament\Vendor\Resources\Products\ProductResource::getUrl('index', ['tableFilters' => ['low_stock' => ['isActive' => true]]]), // Adjust based on actual filter
            ];
        }
        
        if ($newOrdersCount > 0 && ($user->isSuperAdmin() || $vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, 'view_any_order_items'))) {
            $alerts[] = [
                'id' => 'new_orders_alert',
                'label' => "{$newOrdersCount} new orders need fulfillment",
                'icon' => 'heroicon-s-shopping-bag',
                'color' => 'text-blue-600 bg-blue-50',
                'route' => \App\Filament\Vendor\Resources\Orders\OrderResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending']]]),
            ];
        }

        // Greeting Logic
        $hour = Carbon::now('Africa/Lagos')->hour;
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 17) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }

        return view('livewire.vendor.home-dashboard', [
            'vendor' => $vendor,
            'user' => $user,
            'greeting' => $greeting,
            'tiles' => $tiles,
            'alerts' => $alerts,
            'summary' => $summary,
            'storeId' => $storeId,
            'stores' => ActiveStore::accessibleFor($vendor, $user),
        ]);
    }
    
    public function switchStore($storeId)
    {
        $vendor = filament()->getTenant();
        if (ActiveStore::set($vendor, auth()->user(), $storeId)) {
            // refresh happens automatically as Livewire re-renders
        }
    }
}
