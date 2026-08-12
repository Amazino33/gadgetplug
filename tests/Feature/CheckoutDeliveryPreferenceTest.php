<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function makePaidOrder(array $attributes = []): Order
{
    return Order::create(array_merge([
        'reference'        => 'GP-' . fake()->unique()->numerify('######'),
        'customer_name'    => 'Ada Customer',
        'customer_email'   => 'ada@example.com',
        'customer_phone'   => '08012345678',
        'shipping_address' => '12 Test Street',
        'total_amount'     => 50000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ], $attributes));
}

// The success screen is reached by the server putting the reference in the
// session, which is what proves this browser owns the order.
function checkoutSuccessFor(Order $order)
{
    session()->put('payment_success', $order->reference);

    return Volt::test('checkout');
}

test('the success screen offers the delivery choice with nothing preselected', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)
        ->assertSee('When do you want it?')
        ->assertSee('Today')
        ->assertSee('Tomorrow')
        ->assertSee('Pick a date')
        ->assertSet('deliveryUrgency', '');
});

test('tapping today records it immediately with no separate save step', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)->call('chooseDelivery', 'today');

    $order->refresh();

    expect($order->delivery_urgency)->toBe('today')
        ->and($order->preferred_delivery_date->toDateString())->toBe(now()->toDateString())
        ->and($order->delivery_preference_set_at)->not->toBeNull();
});

test('tapping tomorrow records tomorrow', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)->call('chooseDelivery', 'tomorrow');

    expect($order->refresh()->preferred_delivery_date->toDateString())
        ->toBe(now()->addDay()->toDateString());
});

test('a picked date is recorded as scheduled', function () {
    $order  = makePaidOrder();
    $target = now()->addDays(5)->toDateString();

    checkoutSuccessFor($order)
        ->set('deliveryDate', $target)
        ->call('chooseDelivery', 'scheduled');

    $order->refresh();

    expect($order->delivery_urgency)->toBe('scheduled')
        ->and($order->preferred_delivery_date->toDateString())->toBe($target);
});

test('the choice can be changed by tapping another option', function () {
    $order = makePaidOrder();

    $component = checkoutSuccessFor($order)->call('chooseDelivery', 'today');
    expect($order->refresh()->delivery_urgency)->toBe('today');

    $component->call('chooseDelivery', 'tomorrow');

    expect($order->refresh()->delivery_urgency)->toBe('tomorrow')
        ->and($order->preferred_delivery_date->toDateString())->toBe(now()->addDay()->toDateString());
});

test('a date in the past is refused', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)
        ->set('deliveryDate', now()->subDay()->toDateString())
        ->call('chooseDelivery', 'scheduled')
        ->assertHasErrors('deliveryDate');

    expect($order->refresh()->preferred_delivery_date)->toBeNull()
        ->and($order->delivery_urgency)->toBeNull();
});

test('a date beyond the scheduling window is refused', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)
        ->set('deliveryDate', now()->addDays(Order::MAX_SCHEDULE_DAYS + 1)->toDateString())
        ->call('chooseDelivery', 'scheduled')
        ->assertHasErrors('deliveryDate');

    expect($order->refresh()->preferred_delivery_date)->toBeNull();
});

test('unparseable date input is refused rather than stored as nonsense', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)
        ->set('deliveryDate', 'not-a-date')
        ->call('chooseDelivery', 'scheduled')
        ->assertHasErrors('deliveryDate');

    expect($order->refresh()->preferred_delivery_date)->toBeNull();
});

test('an unknown urgency value is ignored', function () {
    $order = makePaidOrder();

    checkoutSuccessFor($order)->call('chooseDelivery', 'whenever');

    expect($order->refresh()->delivery_urgency)->toBeNull();
});

// The reference comes from the server session in mount(), not the request, so a
// visitor who never completed a checkout has nothing to write to.
test('the choice is refused when the visitor has not completed a checkout', function () {
    $order = makePaidOrder();

    // A live checkout form rather than the success screen: a real cart, and no
    // payment_success in the session, so $paid is false.
    $owner    = App\Models\User::factory()->create();
    $vendor   = App\Models\Vendor::create(['user_id' => $owner->id, 'name' => 'Pref Guard Store', 'online_sales_enabled' => true]);
    $category = App\Models\Category::create(['name' => 'Pref Guard Category']);
    $product  = App\Models\Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Pref Guard Product',
        'price'          => 3000,
        'stock_quantity' => 5,
        'status'         => 'published',
        'show_online'    => true,
    ]);

    session()->forget('payment_success');
    session()->put('cart', [$product->id => ['quantity' => 1, 'max' => 5]]);

    Volt::test('checkout')
        ->assertSet('paid', false)
        ->call('chooseDelivery', 'today');

    expect($order->refresh()->delivery_urgency)->toBeNull();
});

test('a saved preference is shown again when the screen is revisited', function () {
    $order = makePaidOrder([
        'delivery_urgency'        => 'scheduled',
        'preferred_delivery_date' => now()->addDays(3)->toDateString(),
    ]);

    checkoutSuccessFor($order)
        ->assertSet('deliveryUrgency', 'scheduled')
        ->assertSet('deliveryDate', now()->addDays(3)->toDateString());
});

test('the label reads back the tapped urgency, not just the date', function () {
    $today = makePaidOrder(['delivery_urgency' => 'today', 'preferred_delivery_date' => now()->toDateString()]);
    $sched = makePaidOrder(['delivery_urgency' => 'scheduled', 'preferred_delivery_date' => now()->addDays(4)->toDateString()]);
    $none  = makePaidOrder();

    expect($today->deliveryPreferenceLabel())->toBe('Today')
        ->and($sched->deliveryPreferenceLabel())->toBe(now()->addDays(4)->format('D, j M Y'))
        ->and($none->deliveryPreferenceLabel())->toBeNull();
});
