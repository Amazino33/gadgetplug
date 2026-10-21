<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use Filament\Pages\Dashboard as BaseDashboard;

// The admin panel's home, kept for super-admins only.
//
// Staff admitted to the panel for one job (the catalogue editor, delivery
// settings, dispatch) have nothing to do here, and /admin is where the panel's login
// lands everyone — so instead of a 403 on arrival they are sent straight to the
// page they may use.
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $user = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return;
        }

        if ($target = static::homeFor($user)) {
            $this->redirect($target);
        }
    }

    /** Where a non-super-admin admin-panel user belongs. */
    public static function homeFor($user): ?string
    {
        if ($user->isCatalogueEditor()) {
            return ProductResource::getUrl('index', panel: 'admin');
        }

        if ($user->hasGlobalPermission('manage_dispatch')) {
            return OrderResource::getUrl('index', panel: 'admin');
        }

        if ($user->hasGlobalPermission('manage_delivery_settings')) {
            return DeliverySettings::getUrl(panel: 'admin');
        }

        return null;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Reachable by single-job staff only to be redirected; it never
        // appears in their navigation.
        return (bool) ($user?->isSuperAdmin() || ($user && static::homeFor($user) !== null));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
