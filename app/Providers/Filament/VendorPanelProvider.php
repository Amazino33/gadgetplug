<?php

namespace App\Providers\Filament;

use App\Models\Vendor;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Navigation\NavigationItem;
use App\Filament\Vendor\Widgets\StoreMetricsOverview;
use App\Filament\Vendor\Widgets\SalesChannelChart;
use App\Filament\Vendor\Widgets\InventoryOverviewWidget;
use App\Filament\Vendor\Widgets\EarningsWidget;
use App\Filament\Vendor\Widgets\RevenueTrendChart;

class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('plug')
            // Off for staff: no vendor resource declares searchable attributes, so
            // this box could only ever answer "No search results found" — worse
            // than absent, because staff conclude the product isn't in the system.
            // The sidebar menu filter below is what they actually needed. Global
            // search stays enabled on the admin panel.
            ->globalSearch(false)
            ->viteTheme('resources/css/filament/vendor/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Vendor/Resources'), for: 'App\Filament\Vendor\Resources')
            ->discoverPages(in: app_path('Filament/Vendor/Pages'), for: 'App\Filament\Vendor\Pages')
            ->widgets([
                StoreMetricsOverview::class,
                RevenueTrendChart::class,
                SalesChannelChart::class,
                InventoryOverviewWidget::class,
                EarningsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\EnsureUserBelongsToVendor::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenant(Vendor::class, slugAttribute: 'slug', ownershipRelationship: 'vendors')
            ->navigationItems([
                NavigationItem::make('POS Terminal')
                    ->url(fn(): string => url('/pos/' . (filament()->getTenant()?->slug ?? '')))
                    ->icon('heroicon-o-computer-desktop')
                    ->group('Store')
                    ->sort(99)
                    ->visible(function () {
                        $user = auth()->user();
                        $vendor = filament()->getTenant();
                        if (! $user || ! $vendor) return false;
                        if ($user->isSuperAdmin()) return true;
                        setPermissionsTeamId($vendor->id);
                        $user->unsetRelation('roles');
                        return $user->hasPermissionTo('access_pos');
                    }),

            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Settings'),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Panel-level context control, so the store the user is operating
            // in is visible and changeable from every screen — not only from
            // the selector grid.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn() => Blade::render('@livewire(\App\Livewire\ActiveStoreSwitcher::class)'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn() => Blade::render("@include('partials.meta-pixel')"),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn() => Blade::render('<x-barcode-scanner />'),
            )
            // Replaces the global search removed above: filters the sidebar itself
            // so staff can jump to a page without scrolling the whole menu.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn() => view('filament.vendor.partials.nav-search'),
            );
    }
}
