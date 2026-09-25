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

// A customer on a slow connection taps Place Order, sees nothing happen, and
// taps again. Before the guard that produced two orders, two references, and —
// on pay-on-delivery — the same stock reserved twice.

function duplicateGuardProduct(int $stock = 10): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Duplicate Guard Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Duplicate Guard Category']);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Duplicate Guard Product',
        'price'          => 6500,
        'cost_price'     => 4000,
        'stock_quantity' => $stock,
        'reserved_stock' => 0,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

/** A checkout filled in and ready to submit, exactly as the form would be. */
function readyCheckout(Product $product, int $quantity = 1): \Livewire\Features\SupportTesting\Testable
{
    // A completed checkout leaves this behind for the confirmation screen. A
    // real customer's next visit consumes it on render; a fresh component in
    // the same test would otherwise mount straight onto that screen and never
    // reach the form.
    Session::forget('payment_success');

    Session::put('cart', [$product->id => ['quantity' => $quantity, 'max' => 10]]);

    return Volt::test('checkout')
        ->set('name', 'Ndianabasi Tester')
        ->set('email', 'ndianabasi@example.com')
        ->set('phone', '08057189760')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, enough characters')
        ->set('paymentMethod', 'pay_on_delivery');
}

test('tapping place order twice creates one order, not two', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product)
        ->call('processCheckout')
        ->call('processCheckout');

    expect(Order::count())->toBe(1);
});

test('the second tap does not reserve the stock again', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product, 2)
        ->call('processCheckout')
        ->call('processCheckout');

    // The cost of the old behaviour: two units held for one real sale, showing
    // as unavailable to every other buyer.
    expect($product->fresh()->reserved_stock)->toBe(2);
});

test('the second tap does not duplicate the order items either', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product, 2)
        ->call('processCheckout')
        ->call('processCheckout');

    $order = Order::firstOrFail();

    expect($order->items()->count())->toBe(1)
        ->and($order->items()->first()->quantity)->toBe(2)
        ->and((float) $order->total_amount)->toBe(13000.0);
});

test('the duplicate still lands the customer on their confirmation', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product)
        ->call('processCheckout')
        ->call('processCheckout')
        ->assertRedirect(route('checkout'));

    expect(session('payment_success'))->toBe(Order::firstOrFail()->reference);
});

test('every order carries a key, so the database itself can refuse a second one', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product)->call('processCheckout');

    $order = Order::firstOrFail();

    expect($order->idempotency_key)->not->toBeNull()
        // The unique index is the backstop for two genuinely concurrent
        // requests, which the pre-check alone would race.
        ->and(fn () => Order::create([
            'reference'       => 'GP-SECONDONE',
            'idempotency_key' => $order->idempotency_key,
            'customer_name'   => 'Someone Else',
            'customer_email'  => 'other@example.com',
            'customer_phone'  => '08000000000',
            'total_amount'    => 6500,
            'status'          => 'pending',
            'payment_method'  => 'pay_on_delivery',
        ]))->toThrow(Illuminate\Database\QueryException::class);
});

test('a different basket is a different checkout and gets its own order', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product, 1)->call('processCheckout');
    readyCheckout($product, 3)->call('processCheckout');

    expect(Order::count())->toBe(2);
});

test('the same customer may order the same thing again once the window passes', function () {
    $product = duplicateGuardProduct();

    readyCheckout($product)->call('processCheckout');

    // Not a duplicate — a repeat customer. Blocking this would be worse than
    // the bug being fixed.
    $this->travel(5)->minutes();

    readyCheckout($product)->call('processCheckout');

    expect(Order::count())->toBe(2);
});

test('two taps either side of a window boundary are still one order', function () {
    $product = duplicateGuardProduct();

    // Pin the clock one second before a 120-second window rolls over, so the
    // second submission computes a different window number than the first.
    $boundary = Illuminate\Support\Carbon::createFromTimestamp(
        (intdiv(now()->getTimestamp(), 120) + 1) * 120 - 1
    );
    $this->travelTo($boundary);

    $component = readyCheckout($product)->call('processCheckout');

    $this->travelTo($boundary->copy()->addSeconds(2));

    $component->call('processCheckout');

    expect(Order::count())->toBe(1);
});
