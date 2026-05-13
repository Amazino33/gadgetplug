<?php

namespace App\Filament\Vendor\Pages;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static string $routePath = '/dashboard';

    protected static ?string $slug = 'dashboard';

    protected static ?int $navigationSort = -2;
}
