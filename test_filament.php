<?php

use App\Models\SupplierLink;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

DB::enableQueryLog();

$vendor1 = Vendor::first();
$vendor2 = Vendor::skip(1)->first();

if (! $vendor1 || ! $vendor2) {
    exit("Need 2 vendors.\n");
}

$link = SupplierLink::firstOrCreate([
    'reseller_vendor_id' => $vendor1->id,
    'supplier_vendor_id' => $vendor2->id,
]);

// Simulate Filament Panel Tenancy
// We just need to trigger the global scope if there is any.
$panel = Filament::getPanel('vendor');
Filament::setCurrentPanel($panel);
Filament::setTenant($vendor1);

echo 'Tenant set to: '.Filament::getTenant()->name."\n";

$link = SupplierLink::with('supplier')->find($link->id);

echo 'Eager loaded supplier: '.($link->supplier ? $link->supplier->name : 'NULL')."\n";

print_r(DB::getQueryLog());
