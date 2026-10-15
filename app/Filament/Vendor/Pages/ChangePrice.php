<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Quantity;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
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
    // The unit whose price is being changed. Null is the product's own (base)
    // unit — the single price this page has always changed.
    public $selectedUnitId = null;
    public $newPrice = null;
    // Half of the chosen unit, for a shop that sells halves. Blank = exactly half.
    public $newHalfPrice = null;
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

    /**
     * The product's other units on sale — a crate, a half crate — smallest
     * first. Empty for a product sold in one unit, which then looks exactly as
     * this page always did.
     *
     * @return Collection<int, ProductUnit>
     */
    public function getExtraUnitsProperty(): Collection
    {
        if (! $this->selectedProductId) {
            return collect();
        }

        return ProductUnit::where('product_id', $this->selectedProductId)
            ->where('is_base', false)
            ->where('is_active', true)
            ->orderBy('conversion_factor')
            ->orderBy('id')
            ->get();
    }

    /** The unit being edited, or null for the base unit. */
    public function getSelectedUnitProperty(): ?ProductUnit
    {
        return $this->selectedUnitId
            ? $this->extraUnits->firstWhere('id', (int) $this->selectedUnitId)
            : null;
    }

    /** How many base units the unit being edited holds: 1 for the base unit. */
    public function getSelectedFactorProperty(): int
    {
        return max(1, (int) ($this->selectedUnit?->conversion_factor ?? 1));
    }

    /** What the unit being edited costs: the base cost times the units in it. */
    public function getSelectedCostProperty(): float
    {
        return (float) $this->selectedProduct?->cost_price * $this->selectedFactor;
    }

    /** What the unit being edited sells for today. */
    public function getSelectedCurrentPriceProperty(): float
    {
        return (float) ($this->selectedUnit?->price ?? $this->selectedProduct?->price ?? 0);
    }

    public function getSellsHalvesProperty(): bool
    {
        return Quantity::allowsHalves(filament()->getTenant());
    }

    public function selectProduct($id)
    {
        $this->selectedProductId = $id;
        $this->search = ''; // clear search
        $this->selectUnit(null);
    }

    /** Switch to another unit of the same product: its own price, cost and half. */
    public function selectUnit($unitId)
    {
        $this->selectedUnitId = $unitId ? (int) $unitId : null;

        $source = $this->selectedUnit ?? $this->selectedProduct;

        $this->newPrice = (float) $this->selectedCurrentPrice;
        $this->newHalfPrice = $source?->half_price !== null ? (float) $source->half_price : null;
        $this->requiresConfirmation = false;
        $this->isSuccess = false;
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

        $unit = $this->selectedUnit;
        $newPriceFloat = (float) $this->newPrice;
        $cost = $this->selectedCost;

        if ($cost > 0 && $newPriceFloat < $cost && !$this->requiresConfirmation) {
            $this->requiresConfirmation = true;
            return; // pause and require explicit confirmation
        }

        // Only a shop that sells halves sees the field; nobody else's saved
        // half price is touched.
        $halfChange = $this->sellsHalves
            ? ['half_price' => ($this->newHalfPrice === null || $this->newHalfPrice === '') ? null : round((float) $this->newHalfPrice, 2)]
            : [];

        DB::transaction(function () use ($product, $unit, $newPriceFloat, $halfChange) {
            if ($unit === null) {
                // The base unit: the product's own price, exactly as before.
                // syncBaseUnit() mirrors it into the base unit row, and the
                // activity log records it because 'price' is in logOnly.
                $product->fill(['price' => $newPriceFloat] + $halfChange)->save();

                return;
            }

            $old = (float) $unit->price;
            $unit->update(['price' => $newPriceFloat] + $halfChange);

            // A unit's price is not on the product's activity log by itself,
            // so the change is written there by hand — the same trail a base
            // price change leaves.
            activity()
                ->performedOn($product)
                ->causedBy(auth()->user())
                ->withProperties(['unit' => $unit->name, 'old' => $old, 'new' => $newPriceFloat])
                ->log("{$unit->name} price changed from ₦".number_format($old, 2).' to ₦'.number_format($newPriceFloat, 2));
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
        $this->selectedUnitId = null;
        $this->newPrice = null;
        $this->newHalfPrice = null;
        $this->requiresConfirmation = false;
        $this->isSuccess = false;
        $this->search = '';
    }
}
