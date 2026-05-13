<?php

namespace App\Providers\Filament;

use App\Filament\Vendor\Resources\AuditSessionResource;
use App\Models\Vendor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Filament\Vendor\Widgets\StoreMetricsOverview;
use App\Filament\Vendor\Widgets\SalesChannelChart;
use App\Filament\Vendor\Widgets\InventoryOverviewWidget;
use App\Filament\Vendor\Widgets\EarningsWidget;

class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('plug')
            ->viteTheme('resources/css/filament/vendor/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Vendor/Resources'), for: 'App\Filament\Vendor\Resources')
            ->discoverPages(in: app_path('Filament/Vendor/Pages'), for: 'App\Filament\Vendor\Pages')
            ->widgets([
                StoreMetricsOverview::class,
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
            ->resources([
                AuditSessionResource::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s');
    }
}
