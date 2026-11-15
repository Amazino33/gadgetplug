<?php

namespace App\Filament\Staff\Pages;

use App\Filament\Staff\Resources\Products\ProductResource;
use Filament\Pages\Dashboard as BaseDashboard;

// The staff panel's home. Staff are here for one job — the product catalogue —
// so /staff lands them on All Products. Super-admins admitted to the panel land
// there too; their own dashboard lives in the admin panel. Never shown in the
// sidebar, exactly like the admin panel's dashboard for single-job staff.
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $this->redirect(ProductResource::getUrl('index', panel: 'staff'));
    }

    public static function canAccess(): bool
    {
        return ProductResource::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
