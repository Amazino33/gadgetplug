<?php

use App\Filament\Vendor\Pages\PriceList;
use App\Models\Vendor;
use Illuminate\Contracts\Http\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$vendor = Vendor::first();
Filament\Facades\Filament::setTenant($vendor);
$page = new PriceList;
try {
    $view = $page->render();
    echo 'SUCCESS';
} catch (Throwable $e) {
    echo $e->getMessage();
}
