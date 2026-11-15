<?php

namespace App\Providers\Filament;

use App\Filament\Staff\Resources\Products\Pages\ListProducts;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The back-office staff panel. Staff log in here for their own jobs — today,
// fixing product photos and online names across every vendor's catalogue —
// rather than the admin panel, which stays super-admin territory.
//
// It reuses the admin theme on purpose: the All Products screen (phone cards,
// quick-filter chips, photo tools) is the same screen staff and super-admins
// both use, and its @source directives already scan app/Filament/** and
// resources/views/filament/** so staff views compile with it too.
class StaffPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('staff')
            ->path('staff')
            ->login()
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Staff/Resources'), for: 'App\Filament\Staff\Resources')
            ->discoverPages(in: app_path('Filament/Staff/Pages'), for: 'App\Filament\Staff\Pages')
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
            ->authMiddleware([
                Authenticate::class,
            ])
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
            );
    }
}
