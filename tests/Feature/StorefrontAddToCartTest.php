<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function addToCartTestProduct(int $stock): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Add To Cart Store']);
    $category = Category::create(['name' => 'Add To Cart Category']);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Add To Cart Product',
        'price'          => 2500,
        'stock_quantity' => $stock,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

test('the catalog page refuses to add an out-of-stock product and shows an error, not a phantom cart line', function () {
    $product = addToCartTestProduct(stock: 0);

    Volt::test('pages.product-catalog')
        ->call('addToCart', $product->id)
        ->assertSet('cartError', "Sorry, \"Add To Cart Product\" is out of stock.");

    expect(Session::get('cart', []))->not->toHaveKey($product->id);
});

test('the catalog page adds an in-stock product without an error', function () {
    $product = addToCartTestProduct(stock: 5);

    Volt::test('pages.product-catalog')
        ->call('addToCart', $product->id)
        ->assertSet('cartError', null);

    expect(Session::get('cart')[$product->id]['quantity'])->toBe(1);
});

test('the product detail page refuses to add an out-of-stock product and shows an error', function () {
    $product = addToCartTestProduct(stock: 0);

    Volt::test('pages.product-detail', ['product' => $product])
        ->call('addToCart')
        ->assertSet('cartError', 'Sorry, this product is out of stock.');

    expect(Session::get('cart', []))->not->toHaveKey($product->id);
});
