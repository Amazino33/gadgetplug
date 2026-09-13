<?php

use App\Filament\Vendor\Pages\PriceList;
use Illuminate\Contracts\Http\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$page = new PriceList;
try {
    $page->getGroupedProducts();
    echo 'SUCCESS';
} catch (Throwable $e) {
    echo $e->getMessage();
}
