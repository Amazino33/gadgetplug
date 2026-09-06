<?php

// Shared fixtures for the VendorLink tests.
//
// In a helper file because Pest loads every test file into one global function
// namespace: helpers declared in a sibling test only exist when that sibling
// happens to be loaded too, so running a file on its own then fails.

use App\Models\Category;
use App\Models\Product;
use App\Models\SupplierLink;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Str;

function linkVendor(string $name, bool $online = true): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => $name.' '.uniqid(),
        'online_sales_enabled' => $online,
    ]);
}

function linkProduct(Vendor $vendor, float $price = 10000, int $stock = 5): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'VendorLink Cat'])->id,
        'name'           => 'Linked Widget '.Str::random(5),
        'price'          => $price,
        'cost_price'     => $price * 0.6,
        'stock_quantity' => $stock,
        'status'         => 'published',
    ]);
}

function makeLink(Vendor $reseller, Vendor $supplier, float $markup = 40): SupplierLink
{
    return SupplierLink::create([
        'reseller_vendor_id' => $reseller->id,
        'supplier_vendor_id' => $supplier->id,
        'markup_percent'     => $markup,
    ]);
}
