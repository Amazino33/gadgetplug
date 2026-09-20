<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Artisan::command("populate:test", function () {
$vendorId = 1;
$sourceStoreId = 1;
$newStoreIds = [10, 11];

$products = App\Models\Product::where("vendor_id", $vendorId)->where("store_id", $sourceStoreId)->get();
$count = 0;
foreach ($newStoreIds as $storeId) {
    foreach ($products as $product) {
        $newProduct = $product->replicate(["slug", "stock_quantity", "reserved_stock"]);
        $newProduct->store_id = $storeId;
        $newProduct->stock_quantity = random_int(10, 50);
        $newProduct->sku = $product->sku . "-BR" . $storeId;
        $newProduct->save();
        $count++;
    }
}
$this->info("Populated {$count} products for the new branches.");
});
