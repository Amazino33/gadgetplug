<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\Product;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use App\Services\ActiveStore;

class ChangePrice extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Change Price';
    protected static ?string $title = 'Change Price';
    protected static ?string $slug = 'change-price';
    protected static ?int $navigationSort = 3;
    protected static string|null|\UnitEnum $navigationGroup = 'Products';
    
    protected string $view = 'filament.vendor.pages.change-price';

    public $search = '';
    public $selectedProductId = null;
    public $newPrice = null;
    public $requiresConfirmation = false;
    public $isSuccess = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();
        if (!$vendor || !$user) return false;
        if ($user->isSuperAdmin() || $vendor->isOwner($user)) return true;
        return $user->hasVendorPermission($vendor->id, 'edit_products');
    }

    public function getProductsProperty()
    {
        if (empty($this->search)) {
            return collect();
        }

        $vendor = filament()->getTenant();
        $activeStoreId = ActiveStore::currentId(); // Active store context for searching

        return Product::where('vendor_id', $vendor->id)
            ->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('sku', 'like', "%{$this->search}%")
                  ->orWhere('barcode', 'like', "%{$this->search}%");
            })
            // Only products in the active store if one exists, otherwise all
            ->when($activeStoreId, function ($q) use ($activeStoreId) {
                $q->whereHas('storeStocks', function ($q2) use ($activeStoreId) {
                    $q2->where('store_id', $activeStoreId);
                });
            })
            ->limit(10)
            ->get();
    }

    public function getSelectedProductProperty()
    {
        if (!$this->selectedProductId) return null;
        return Product::where('vendor_id', filament()->getTenant()->id)
            ->find($this->selectedProductId);
    }

    public function selectProduct($id)
    {
        $this->selectedProductId = $id;
        $product = $this->selectedProduct;
        $this->newPrice = (float) $product->price;
        $this->requiresConfirmation = false;
        $this->isSuccess = false;
        $this->search = ''; // clear search
    }

    public function adjustPrice($amount)
    {
        $this->newPrice = max(0, (float) $this->newPrice + $amount);
        $this->requiresConfirmation = false; // reset confirmation if price changes again
    }

    public function savePrice()
    {
        $product = $this->selectedProduct;
        if (!$product) return;

        $newPriceFloat = (float) $this->newPrice;
        $cost = (float) $product->cost_price;

        if ($cost > 0 && $newPriceFloat < $cost && !$this->requiresConfirmation) {
            $this->requiresConfirmation = true;
            return; // pause and require explicit confirmation
        }

        DB::transaction(function () use ($product, $newPriceFloat) {
            $product->price = $newPriceFloat;
            // The model trait LogsActivity will automatically log this since 'price' is in logOnly
            $product->save();
        });

        Notification::make()
            ->title('Price updated successfully')
            ->success()
            ->send();

        $this->isSuccess = true;
    }

    public function cancelEdit()
    {
        $this->selectedProductId = null;
        $this->newPrice = null;
        $this->requiresConfirmation = false;
        $this->isSuccess = false;
        $this->search = '';
    }
}
