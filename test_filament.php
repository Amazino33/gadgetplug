<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

\DB::enableQueryLog();

$vendor1 = \App\Models\Vendor::first();
$vendor2 = \App\Models\Vendor::skip(1)->first();

if (!$vendor1 || !$vendor2) {
    die("Need 2 vendors.\n");
}

$link = \App\Models\SupplierLink::firstOrCreate([
    'reseller_vendor_id' => $vendor1->id,
    'supplier_vendor_id' => $vendor2->id,
]);

// Simulate Filament Panel Tenancy
// We just need to trigger the global scope if there is any.
$panel = \Filament\Facades\Filament::getPanel('vendor');
\Filament\Facades\Filament::setCurrentPanel($panel);
\Filament\Facades\Filament::setTenant($vendor1);

echo "Tenant set to: " . \Filament\Facades\Filament::getTenant()->name . "\n";

$link = \App\Models\SupplierLink::with('supplier')->find($link->id);

echo "Eager loaded supplier: " . ($link->supplier ? $link->supplier->name : "NULL") . "\n";

print_r(\DB::getQueryLog());
