<?php

use App\Models\SupplierLink;
use App\Models\Vendor;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$link = SupplierLink::first();
if ($link) {
    echo "Link ID: {$link->id}\n";
    echo "Supplier ID: {$link->supplier_vendor_id}\n";
    echo 'Supplier relation: '.json_encode($link->supplier)."\n";
    $vendor = Vendor::find($link->supplier_vendor_id);
    echo 'Direct vendor find: '.json_encode($vendor)."\n";
} else {
    echo "No link found.\n";
}
