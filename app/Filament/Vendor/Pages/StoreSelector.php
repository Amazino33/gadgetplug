<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Store;
use App\Services\ActiveStore;
use App\Services\Inventory\StoreStockMetrics;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

class StoreSelector extends Page
{
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-building-storefront';
    protected static string|null|UnitEnum   $navigationGroup = 'Store';
    protected static ?string $navigationLabel = 'Switch Store';
    protected static ?string $title           = 'Your Stores';
    protected static ?int    $navigationSort  = 0;
    protected string $view = 'filament.vendor.pages.store-selector';

    // Everyone who can see products can see the stores they hold them in —
    // the grid itself reveals nothing beyond what each card's own permissions
    // already allow, and the cost figure is gated separately below.
    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user && (
            $user->isSuperAdmin() ||
            $user->hasVendorPermission($vendor->id, 'view_products')
        );
    }

    /** @return Collection<int, Store> */
    public function stores(): Collection
    {
        return ActiveStore::accessibleFor(filament()->getTenant(), auth()->user());
    }

    public function metrics(): Collection
    {
        return StoreStockMetrics::forStores($this->stores()->pluck('id'));
    }

    public function activeStoreId(): ?int
    {
        return ActiveStore::get(filament()->getTenant(), auth()->user())?->id;
    }

    // Reused rather than re-implemented: this is the codebase's single answer
    // to "may this person see what stock cost", and it already treats the
    // owner as always allowed.
    public function canSeeCostValue(): bool
    {
        return ProductForm::canSeeCostPrice();
    }

    public function selectStore(int $storeId): void
    {
        $vendor = filament()->getTenant();

        // The service refuses a store outside this vendor or outside the
        // user's accessible set, so a forged store id from the browser is
        // rejected here rather than trusted because the card was rendered.
        if (! ActiveStore::set($vendor, auth()->user(), $storeId)) {
            Notification::make()
                ->title('You do not have access to that store.')
                ->danger()
                ->send();

            return;
        }

        $store = Store::find($storeId);

        Notification::make()
            ->title("Now working in {$store->name}")
            ->success()
            ->send();

        // Straight into the operating context the card represents, rather than
        // leaving them looking at the grid wondering whether it took.
        $this->redirect(
            ProductResource::getUrl('index', tenant: filament()->getTenant()),
            navigate: false,
        );
    }
}
