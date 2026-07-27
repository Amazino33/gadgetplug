<?php

use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function setUpImageEnhancementVendor(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Image Enhancement Test Store']);
    $category = Category::create(['name' => 'Test Category']);

    return compact('owner', 'vendor', 'category');
}

// Note: CreateProduct isn't exercised here (only EditProduct) — both pages
// render the exact same ProductForm::configure() schema, and CreateProduct
// currently trips an unrelated pre-existing bug in AuditSessionResource.php
// (a wrong `use` import that only surfaces once Filament's panel-wide
// resource discovery is fully evaluated, which its render path hits but
// EditProduct/ListProducts' don't) — see conversation notes for details.
test('the product edit form shows the Enhance with AI action next to the photo upload', function () {
    $data = setUpImageEnhancementVendor();

    $product = Product::create([
        'vendor_id' => $data['vendor']->id,
        'category_id' => $data['category']->id,
        'name' => 'Enhanceable Widget',
        'price' => 1000,
        'status' => 'published',
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertOk()
        ->assertSee('Enhance with AI');
});

test('stripTransientAiFields removes only the AI review-state keys, leaving real product data untouched', function () {
    $data = [
        'name' => 'Real Widget',
        'description' => 'A real description',
        'ai_enhanced_file_key' => 'abc123.jpg',
        'ai_suggested_filename' => 'real-widget',
        'ai_suggested_alt_text' => 'A real widget photo',
        'ai_suggested_title' => 'Real Widget',
    ];

    $cleaned = ProductForm::stripTransientAiFields($data);

    expect($cleaned)->toBe([
        'name' => 'Real Widget',
        'description' => 'A real description',
    ]);
});
