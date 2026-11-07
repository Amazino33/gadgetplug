<?php

namespace App\Filament\Vendor;

use App\Filament\Vendor\Pages\Dashboard;
use App\Filament\Vendor\Pages\ShopProfile;
use App\Filament\Vendor\Resources\Products\ProductResource;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;

/**
 * The sidebar a free shop sees: its home, Products and Shop Profile.
 *
 * Set for the current request only, by EnforceFreePlan, once it knows the
 * tenant is on the free plan — so paid vendors keep the panel's normal,
 * discovered navigation and never run this. Hiding the menu is the courtesy;
 * EnforceFreePlan refusing every other URL is the control.
 */
final class FreeShopNavigation
{
    private static bool $applied = false;

    /**
     * Puts the panel's normal navigation back if an earlier request in this
     * same process swapped it (a queue worker or test run serving several
     * requests). A no-op in ordinary PHP-FPM, where every request starts clean.
     */
    public static function reset(): void
    {
        if (self::$applied) {
            filament()->getCurrentPanel()?->navigation(true);
            self::$applied = false;
        }
    }

    public static function apply(): void
    {
        self::$applied = true;

        filament()->getCurrentPanel()?->navigation(
            fn (NavigationBuilder $builder): NavigationBuilder => $builder->items([
                NavigationItem::make('My Shop')
                    ->icon('heroicon-o-home')
                    ->url(fn (): string => Dashboard::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.vendor.pages.dashboard'))
                    ->sort(1),

                NavigationItem::make('Products')
                    ->icon('heroicon-o-cube')
                    ->url(fn (): string => ProductResource::getUrl('index'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.vendor.resources.products.*'))
                    ->sort(2),

                NavigationItem::make('Shop Profile')
                    ->icon('heroicon-o-building-storefront')
                    ->url(fn (): string => ShopProfile::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.vendor.pages.shop-profile'))
                    ->sort(3),
            ]),
        );
    }
}
