<?php

use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the product list finds a product by barcode, extra barcode or SKU as well as by name', function () {
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Search Shop '.uniqid()]);
    $cat    = Category::create(['name' => 'Cat '.uniqid()]);

    $soap = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $cat->id, 'name' => 'Bar Soap',
        'sku' => 'SOAP-1', 'barcode' => '6151234500011', 'price' => 400, 'status' => 'published',
    ]);
    ProductBarcode::create(['product_id' => $soap->id, 'barcode' => '6151234500099']);

    $milk = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $cat->id, 'name' => 'Peak Milk',
        'barcode' => '5900000000017', 'price' => 950, 'status' => 'published',
    ]);

    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    foreach (['6151234500011', '6151234500099', 'SOAP-1', 'Bar Soap'] as $term) {
        $found = Livewire::test(ListProducts::class)
            ->set('search', $term)
            ->instance()
            ->getProducts()
            ->pluck('id')
            ->all();

        expect($found)->toBe([$soap->id]);
    }
});
