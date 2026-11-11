<?php

namespace App\Providers\Filament;

use App\Filament\Pages\DeliverySettings;
use App\Filament\Resources\AppFeatures\Pages\EditAppFeature;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Tips\Pages\CreateTip;
use App\Filament\Resources\Tips\Pages\EditTip;
use App\Filament\Resources\Tips\Pages\ListTips;
use App\Services\Messaging\MessagingService;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // The bell: stale pay-on-delivery alerts (ReleaseStaleReservationsJob)
            // land here as database notifications.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            // Only what our own admin markup needs on top of Filament's styles
            // (phone layout of All Products, the test-mode banner).
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // App\Filament\Pages\Dashboard (discovered above) replaces Filament's
            // default so the catalogue editor is redirected rather than shown it.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::CONTENT_BEFORE,
                function (): string {
                    MessagingService::warnRedirectActiveOncePerDay();

                    return Blade::render("@include('partials.messaging-redirect-banner')");
                },
            )
            // "No photo" / "No online name" chips beside the All Products search.
            ->renderHook(
                TablesRenderHook::TOOLBAR_SEARCH_AFTER,
                fn () => view('filament.admin.products.quick-filters'),
                scopes: ListProducts::class,
            )
            // Camera / gallery buttons and photo ordering in "Manage images".
            ->renderHook(
                PanelsRenderHook::SCRIPTS_BEFORE,
                fn () => view('filament.admin.products.photo-tools-script'),
                scopes: ListProducts::class,
            )
            // Leaflet and the map components, only where a page draws a map.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.admin.partials.leaflet'),
                scopes: [DeliverySettings::class, ViewOrder::class],
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => Blade::render("@include('partials.meta-pixel')"),
            )
            // In-app tips can target the admin panel too, for testing.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('engagement.tip-hook'),
            )
            // The tip editor's phone preview plays media with the same script.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('engagement.tip-scripts'),
                scopes: [ListTips::class, CreateTip::class, EditTip::class, EditAppFeature::class],
            );
    }

    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
