<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

// End-to-end: the real checkout Volt component, not just the isolated
// service — this is the actual hook point a bug could hide in.

function makeCheckoutProduct(): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Checkout Attribution Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Checkout Attribution Category']);

    return Product::create([
        'vendor_id'       => $vendor->id,
        'category_id'     => $category->id,
        'name'            => 'Checkout Attribution Product',
        'price'           => 5000,
        'stock_quantity'  => 10,
        'status'          => 'published',
        'commission_rate' => 10.0,
    ]);
}

test('a referral code entered at checkout creates a real commission on the resulting order', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $product   = makeCheckoutProduct();

    Session::put('cart', [$product->id => ['quantity' => 1]]);

    Volt::test('checkout')
        ->set('name', 'Jane Buyer')
        ->set('email', 'jane@example.com')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '1 Test Street, long enough to pass validation')
        ->set('paymentMethod', 'pay_on_delivery')
        ->set('referralCode', $affiliate->code)
        ->call('processCheckout');

    $order = Order::where('customer_email', 'jane@example.com')->firstOrFail();
    $commission = AffiliateCommission::where('order_id', $order->id)->first();

    expect($commission)->not->toBeNull()
        ->and($commission->affiliate_id)->toBe($affiliate->id)
        ->and($commission->amount)->toEqual('500.00'); // 10% of 5000
});

test('checkout with no referral code and no cookie creates no commission', function () {
    $product = makeCheckoutProduct();

    Session::put('cart', [$product->id => ['quantity' => 1]]);

    Volt::test('checkout')
        ->set('name', 'Jane Buyer')
        ->set('email', 'no-affiliate@example.com')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '1 Test Street, long enough to pass validation')
        ->set('paymentMethod', 'pay_on_delivery')
        ->call('processCheckout');

    $order = Order::where('customer_email', 'no-affiliate@example.com')->firstOrFail();

    expect(AffiliateCommission::where('order_id', $order->id)->exists())->toBeFalse();
});

test('a bogus referral code at checkout does not block the order from being placed', function () {
    $product = makeCheckoutProduct();

    Session::put('cart', [$product->id => ['quantity' => 1]]);

    Volt::test('checkout')
        ->set('name', 'Jane Buyer')
        ->set('email', 'bogus-code@example.com')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '1 Test Street, long enough to pass validation')
        ->set('paymentMethod', 'pay_on_delivery')
        ->set('referralCode', 'TOTALLY-FAKE-CODE')
        ->call('processCheckout');

    $order = Order::where('customer_email', 'bogus-code@example.com')->first();

    expect($order)->not->toBeNull()
        ->and(AffiliateCommission::where('order_id', $order->id)->exists())->toBeFalse();
});
