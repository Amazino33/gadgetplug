<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Pages\Dashboard as BaseDashboard;

// The admin panel's home, kept for super-admins only.
//
// The catalogue editor is admitted to the panel but has nothing to do here, and
// /admin is where the panel's login lands everyone — so instead of a 403 on
// arrival, they are sent straight to All Products, the one page they may use.
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $user = auth()->user();

        if ($user && ! $user->isSuperAdmin() && $user->isCatalogueEditor()) {
            $this->redirect(ProductResource::getUrl('index'));
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // The editor may reach it only to be redirected; it never appears in
        // their navigation.
        return (bool) ($user?->isSuperAdmin() || $user?->isCatalogueEditor());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
