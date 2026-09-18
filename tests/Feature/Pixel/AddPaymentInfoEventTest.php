<?php

use App\Jobs\SendMetaConversionEventJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function paymentInfoProduct(): Product
{
    $vendor = Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'AddPaymentInfo Store',
        'online_sales_enabled' => true,
    ]);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'AddPaymentInfo Category'])->id,
        'name'           => 'AddPaymentInfo Product',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

/**
 * Every queued event of the given name.
 *
 * Reads the fake's record rather than asserting against it: some of these
 * tests are about an event NOT being sent, and Queue::assertPushed fails
 * outright when the queue is empty — which is the very state they mean to
 * confirm.
 *
 * @return array<int, array<string, mixed>>
 */
function queuedMetaEvents(string $name): array
{
    return Queue::pushed(SendMetaConversionEventJob::class)
        ->map(fn (SendMetaConversionEventJob $job) => $job->payload()['data'][0] ?? null)
        ->filter(fn (?array $event) => $event && $event['event_name'] === $name)
        ->values()
        ->all();
}

/** The first event of the given name that was queued, or null. */
function queuedMetaEvent(string $name): ?array
{
    return queuedMetaEvents($name)[0] ?? null;
}

test('choosing a payment method at checkout dispatches a queued AddPaymentInfo event', function () {
    Queue::fake();

    $product = paymentInfoProduct();
    Session::put('cart', [$product->id => ['quantity' => 2, 'max' => 10]]);

    Volt::test('checkout')->call('choosePayment', 'pay_on_delivery');

    $event = queuedMetaEvent('AddPaymentInfo');

    expect($event)->not->toBeNull()
        ->and($event['custom_data']['currency'])->toBe('NGN')
        ->and($event['custom_data']['value'])->toBe(10000.0)
        ->and($event['custom_data']['content_ids'])->toBe([$product->id])
        ->and($event['custom_data']['payment_method'])->toBe('pay_on_delivery');
});

test('choosing a payment method on a product page dispatches AddPaymentInfo with that product', function () {
    Queue::fake();

    $product = paymentInfoProduct();

    Volt::test('pages.product-detail', ['product' => $product])
        ->set('quantity', 3)
        ->call('buyNow', 'paystack');

    $event = queuedMetaEvent('AddPaymentInfo');

    expect($event)->not->toBeNull()
        ->and($event['custom_data']['value'])->toBe(15000.0)
        ->and($event['custom_data']['content_ids'])->toBe([$product->id])
        ->and($event['custom_data']['payment_method'])->toBe('paystack');
});

test('opening the payment screen on a product page dispatches InitiateCheckout', function () {
    Queue::fake();

    $product = paymentInfoProduct();

    Volt::test('pages.product-detail', ['product' => $product])
        ->set('quantity', 2)
        ->call('openPaymentChoice');

    $event = queuedMetaEvent('InitiateCheckout');

    expect($event)->not->toBeNull()
        ->and($event['custom_data']['value'])->toBe(10000.0)
        ->and($event['custom_data']['content_ids'])->toBe([$product->id]);
});

test('reopening the payment screen does not fire InitiateCheckout a second time', function () {
    Queue::fake();

    $product = paymentInfoProduct();

    $component = Volt::test('pages.product-detail', ['product' => $product])
        ->call('openPaymentChoice')
        ->call('openPaymentChoice')
        ->call('openPaymentChoice');

    $component->assertSet('initiateCheckoutFired', true);

    expect(queuedMetaEvents('InitiateCheckout'))->toHaveCount(1);
});

test('checkout reached from a product page does not fire InitiateCheckout again', function () {
    Queue::fake();

    $product = paymentInfoProduct();
    Session::put('cart', [$product->id => ['quantity' => 1, 'max' => 10]]);
    session()->put('checkout_payment_method', 'pay_on_delivery');

    Volt::test('checkout');

    expect(queuedMetaEvent('InitiateCheckout'))->toBeNull();
});

test('the product page renders a browser ViewContent sharing the server event id', function () {
    // Without this the CAPI job runs inline and a configured pixel id sends a
    // real request to Meta, which answers 400 for an id that is not theirs.
    Queue::fake();

    config()->set('services.meta.pixel_id', '1234567890');

    $product = paymentInfoProduct();

    $component = Volt::test('pages.product-detail', ['product' => $product]);

    $eventId = $component->get('viewContentEventId');

    expect($eventId)->not->toBeNull();

    $component->assertSee("fbq('track', 'ViewContent'", false)
        ->assertSee($eventId, false);
});

test('an unknown payment method is refused rather than recorded', function () {
    Queue::fake();

    $product = paymentInfoProduct();
    Session::put('cart', [$product->id => ['quantity' => 1, 'max' => 10]]);

    Volt::test('checkout')
        ->call('choosePayment', 'bank_transfer')
        ->assertSet('step', 1)
        ->assertSet('paymentMethod', '');

    expect(queuedMetaEvent('AddPaymentInfo'))->toBeNull();
});
