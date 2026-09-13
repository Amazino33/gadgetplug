<?php

use App\Filament\Vendor\Resources\PosSales\PosSaleResource;

require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/bootstrap/app.php';
new PosSaleResource;
echo 'OK';
