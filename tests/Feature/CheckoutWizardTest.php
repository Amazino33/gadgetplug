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

function checkoutWizardProduct(): Product
{
    $vendor = Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Wizard Store',
        'online_sales_enabled' => true,
    ]);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Wizard Category'])->id,
        'name'           => 'Wizard Product',
        'price'          => 15000,
        'stock_quantity' => 10,
        'status'         => 'published',
        'show_online'    => true,
    ]);
}

function checkoutWizardCart(Product $product, int $quantity = 1): void
{
    Session::forget('payment_success');
    Session::put('cart', [$product->id => ['quantity' => $quantity, 'max' => 10]]);
}

// ── Where the flow opens ────────────────────────────────────────────────────

test('arriving from the cart opens on the payment step', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->assertSet('step', 1)
        ->assertSet('paymentMethod', '')
        ->assertSee('How would you like to pay?');
});

test('arriving from a product page Buy Now skips straight to delivery', function () {
    checkoutWizardCart(checkoutWizardProduct());
    session()->put('checkout_payment_method', 'pay_on_delivery');

    Volt::test('checkout')
        ->assertSet('step', 2)
        ->assertSet('paymentMethod', 'pay_on_delivery')
        ->assertSee('Where should we deliver to?');
});

test('a payment method planted in the session by something else is ignored', function () {
    checkoutWizardCart(checkoutWizardProduct());
    session()->put('checkout_payment_method', 'crypto');

    Volt::test('checkout')->assertSet('step', 1)->assertSet('paymentMethod', '');
});

// ── Moving between steps ────────────────────────────────────────────────────

test('choosing a payment method advances to delivery', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'paystack')
        ->assertSet('step', 2)
        ->assertSet('paymentMethod', 'paystack');
});

test('delivery details must be complete before the confirm step', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->call('goToConfirm')
        ->assertHasErrors(['name', 'phone', 'lga', 'address'])
        ->assertSet('step', 2);
});

test('a complete delivery step reaches confirm', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->set('name', 'Aniekan Udo')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, near the market')
        ->call('goToConfirm')
        ->assertHasNoErrors()
        ->assertSet('step', 3);
});

test('going back keeps everything already typed', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->set('name', 'Aniekan Udo')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, near the market')
        ->call('goToConfirm')
        ->call('goToStep', 2)
        ->assertSet('step', 2)
        ->assertSet('name', 'Aniekan Udo')
        ->assertSet('phone', '08012345678')
        ->assertSet('address', '12 Test Close, near the market')
;
});

test('the progress bar cannot be used to jump forward past an unfilled step', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->call('goToStep', 3)
        ->assertSet('step', 2);
});

// ── Email: optional on delivery, required online ────────────────────────────

test('a pay-on-delivery order goes through with no email at all', function () {
    $product = checkoutWizardProduct();
    checkoutWizardCart($product);

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->set('name', 'Aniekan Udo')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, near the market')
        ->call('goToConfirm')
        ->call('processCheckout')
        ->assertHasNoErrors();

    $order = Order::firstOrFail();

    expect($order->customer_email)->toBeNull()
        ->and($order->payment_method)->toBe('pay_on_delivery')
        ->and($order->status)->toBe('confirmed');
});

test('an email given on the delivery path is still kept', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->set('name', 'Aniekan Udo')
        ->set('email', 'aniekan@example.com')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, near the market')
        ->call('goToConfirm')
        ->call('processCheckout');

    expect(Order::firstOrFail()->customer_email)->toBe('aniekan@example.com');
});

test('paying online still requires an email, because Paystack will not open a transaction without one', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'paystack')
        ->set('name', 'Aniekan Udo')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, near the market')
        ->call('goToConfirm')
        ->assertHasErrors(['email'])
        ->assertSet('step', 2);
});

// The delivery-time picker was removed: it was a required step standing between
// a shopper and the Continue button, for a question the rider settles on
// WhatsApp anyway. A checkout that asks again is a regression.
test('the delivery step does not ask when the customer wants the order', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->assertDontSee('When do you want it?')
        ->assertDontSee('Pick a date');
});

test('a complete delivery step needs nothing but name, phone, area and address', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->call('choosePayment', 'pay_on_delivery')
        ->set('name', 'Aniekan Udo')
        ->set('phone', '08012345678')
        ->set('lga', 'Uyo')
        ->set('address', '12 Test Close, near the market')
        ->call('goToConfirm')
        ->assertHasNoErrors()
        ->assertSet('step', 3);
});

// ── The guard that matters most ─────────────────────────────────────────────

test('processCheckout enforces the delivery rules even when the steps are skipped', function () {
    checkoutWizardCart(checkoutWizardProduct());

    // Straight to the end, the way a crafted request would.
    Volt::test('checkout')
        ->set('paymentMethod', 'pay_on_delivery')
        ->call('processCheckout')
        ->assertHasErrors(['name', 'phone', 'lga', 'address']);

    expect(Order::count())->toBe(0);
});

// ── The page is the order, and nothing else ─────────────────────────────────

test('the checkout steps are stripped of storefront chrome', function () {
    checkoutWizardCart(checkoutWizardProduct());

    Volt::test('checkout')
        ->assertSee('Secure checkout')
        ->assertDontSee('<footer', false)
        ->assertDontSee('id="category-nav"', false)
        ->assertDontSee('MOBILE BOTTOM NAV', false);
});

test('the confirmation screen hands the store back', function () {
    $order = Order::create([
        'reference'        => 'GP-CHROMETEST',
        'customer_name'    => 'Aniekan Udo',
        'customer_phone'   => '08012345678',
        'shipping_address' => 'Uyo, Akwa Ibom State — 12 Test Close',
        'total_amount'     => 15000,
        'status'           => 'confirmed',
        'payment_method'   => 'pay_on_delivery',
    ]);

    session()->put('payment_success', $order->reference);

    // Done deciding — browsing on from here is the useful next thing, so the
    // footer and the bottom nav come back.
    Volt::test('checkout')
        ->assertSee('Order Confirmed')
        ->assertSee('<footer', false);
});
