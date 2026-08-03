<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function checkoutTestProduct(): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Checkout Guard Store']);
    $category = Category::create(['name' => 'Checkout Guard Category']);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Checkout Guard Product',
        'price'          => 3000,
        'stock_quantity' => 5,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

test('landing on checkout with only a zero-quantity cart line redirects to the cart instead', function () {
    $product = checkoutTestProduct();
    Session::put('cart', [$product->id => ['quantity' => 0, 'max' => 5]]);

    Volt::test('checkout')->assertRedirect(route('cart'));

    expect(Order::count())->toBe(0);
});

test('a zero-quantity line mixed with a real item is dropped, checkout still works for the rest', function () {
    $good = checkoutTestProduct();
    $zero = checkoutTestProduct();

    Session::put('cart', [
        $good->id => ['quantity' => 2, 'max' => 5],
        $zero->id => ['quantity' => 0, 'max' => 5],
    ]);

    $component = Volt::test('checkout')
        ->set('name', 'Jane Tester')
        ->set('email', 'jane@example.com')
        ->set('phone', '08040000000')
        ->set('lga', 'Uyo')
        ->set('address', '1 Test Street, enough characters')
        ->set('paymentMethod', 'pay_on_delivery')
        ->call('processCheckout');

    $order = Order::first();

    expect($order)->not->toBeNull()
        ->and((float) $order->total_amount)->toBe(6000.0)
        ->and($order->items()->count())->toBe(1)
        ->and($order->items()->first()->product_id)->toBe($good->id);
});
