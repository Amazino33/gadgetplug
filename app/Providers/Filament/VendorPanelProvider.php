<?php

namespace App\Providers\Filament;

use App\Filament\Vendor\Resources\AuditSessionResource;
use App\Models\Vendor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use App\Filament\Widgets\StoreMetricsOverview;
use App\Filament\Widgets\SalesChannelChart; 

class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('plug')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Vendor/Resources'), for: 'App\Filament\Vendor\Resources')
            ->discoverPages(in: app_path('Filament/Vendor/Pages'), for: 'App\Filament\Vendor\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Vendor/Widgets'), for: 'App\Filament\Vendor\Widgets')
            ->widgets([
                StoreMetricsOverview::class,
                SalesChannelChart::class,
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
            ->tenant(Vendor::class, ownershipRelationship: 'vendors')
            ->resources([
                AuditSessionResource::class, // Explicitly tell Filament to load this!
            ]);
    }
}
