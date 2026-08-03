<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;

uses(RefreshDatabase::class);

// A customer was able to check out with a cart line at quantity 0, producing
// a ₦0 order with a phantom item on it — traced to add() silently capping a
// fresh add down to zero when stock had run out, rather than refusing it.

function cartServiceProduct(int $stock = 5): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Cart Service Store']);
    $category = Category::create(['name' => 'Cart Service Category']);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Cart Service Product',
        'price'          => 2000,
        'stock_quantity' => $stock,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

test('adding an out-of-stock product is refused and never creates a zero-quantity line', function () {
    $product = cartServiceProduct(stock: 0);

    $result = app(CartService::class)->add($product);

    expect($result)->toBeFalse()
        ->and(Session::get('cart', []))->not->toHaveKey($product->id);
});

test('adding an in-stock product succeeds normally', function () {
    $product = cartServiceProduct(stock: 5);

    $result = app(CartService::class)->add($product, 2);

    expect($result)->toBeTrue()
        ->and(Session::get('cart')[$product->id]['quantity'])->toBe(2);
});

test('adding more than available stock caps the quantity but never to zero given positive stock', function () {
    $product = cartServiceProduct(stock: 3);

    app(CartService::class)->add($product, 10);

    expect(Session::get('cart')[$product->id]['quantity'])->toBe(3);
});
