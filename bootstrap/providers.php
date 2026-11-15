<?php

use App\Providers\AppServiceProvider;
use App\Providers\EngagementServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\StaffPanelProvider;
use App\Providers\Filament\VendorPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    EngagementServiceProvider::class,
    AdminPanelProvider::class,
    StaffPanelProvider::class,
    VendorPanelProvider::class,
    FortifyServiceProvider::class,
    VoltServiceProvider::class,
];
