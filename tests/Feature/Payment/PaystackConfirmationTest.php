<?php

use App\Models\DeliveryMessage;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformMessagingSetting;
use App\Models\Product;
use App\Models\ProductStoreStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../VendorLink/Helpers.php';

uses(RefreshDatabase::class);

// The callback (browser redirect) and the webhook (server-to-server) both
// confirm the same payment and race each other. Whichever lands first does the
// work; the other must change nothing.

const PAYSTACK_TEST_SECRET = 'sk_test_confirmation_secret';

function paystackOrder(bool $withLinkedLine = false): array
{
    config([
        'services.paystack.secret_key'        => PAYSTACK_TEST_SECRET,
        'services.messaging.whatsapp_driver'  => 'log_null',
        'services.messaging.sms_driver'       => 'log_null',
    ]);

    // So the storekeeper's new-order alert has somewhere to go and counts
    // toward "one set of messages".
    PlatformMessagingSetting::current()->update(['fallback_storekeeper_whatsapp' => '08011112222']);

    $vendor  = linkVendor('Paystack Seller');
    $product = linkProduct($vendor, price: 10000, stock: 5);

    $order = Order::create([
        'reference'        => 'GP-PS-'.uniqid(),
        'customer_name'    => 'Pay Online',
        'customer_email'   => 'pay@example.com',
        'customer_phone'   => '08050000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => 20000,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $vendor->id, 'quantity' => 2, 'unit_price' => 10000,
    ]);

    $listing = null;

    if ($withLinkedLine) {
        $supplier = linkVendor('Paystack Supplier');
        $source   = linkProduct($supplier, price: 8000, stock: 9);
        $link     = makeLink($vendor, $supplier);

        // A resold listing: points at the supplier's product and holds no stock.
        $listing = Product::create([
            'vendor_id'         => $vendor->id,
            'store_id'          => $vendor->defaultStore->id,
            'category_id'       => $source->category_id,
            'source_product_id' => $source->id,
            'supplier_link_id'  => $link->id,
            'name'              => 'Resold Widget',
            'price'             => 11200,
            'stock_quantity'    => 0,
            'status'            => 'published',
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $listing->id,
            'vendor_id' => $vendor->id, 'quantity' => 1, 'unit_price' => 11200,
        ]);

        $order->update(['total_amount' => 31200]);
    }

    return ['order' => $order->fresh(), 'product' => $product, 'listing' => $listing];
}

function fakePaystackVerify(Order $order, ?int $amountKobo = null, string $currency = 'NGN'): void
{
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data'   => [
                'status'    => 'success',
                'reference' => $order->reference,
                'amount'    => $amountKobo ?? (int) round((float) $order->total_amount * 100),
                'currency'  => $currency,
            ],
        ]),
    ]);
}

function postPaystackWebhook(Order $order, ?string $signature = null, string $event = 'charge.success')
{
    $raw = json_encode(['event' => $event, 'data' => ['reference' => $order->reference]]);

    return test()->call('POST', '/paystack/webhook', [], [], [], [
        'CONTENT_TYPE'               => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE'  => $signature ?? hash_hmac('sha512', $raw, PAYSTACK_TEST_SECRET),
    ], $raw);
}

function reservationCount(Order $order): int
{
    return InventoryLedger::where('reference', $order->reference)
        ->where('transaction_type', 'reserved')
        ->count();
}

test('the callback skips a linked line and the order ends paid', function () {
    $c = paystackOrder(withLinkedLine: true);
    fakePaystackVerify($c['order']);

    test()->get(route('payment.callback', ['reference' => $c['order']->reference]))
        ->assertRedirect(route('checkout'));

    expect($c['order']->fresh()->status)->toBe('paid')
        ->and(reservationCount($c['order']))->toBe(1)
        ->and(ProductStoreStock::where('product_id', $c['product']->id)->value('reserved'))->toBe(2)
        ->and(ProductStoreStock::where('product_id', $c['listing']->id)->value('reserved') ?? 0)->toBe(0);
});

test('a webhook with a bad signature is rejected and the order is untouched', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order']);

    postPaystackWebhook($c['order'], signature: 'not-the-signature')->assertStatus(401);

    expect($c['order']->fresh()->status)->toBe('pending')
        ->and(reservationCount($c['order']))->toBe(0);
    Http::assertNothingSent();
});

test('a signed webhook whose verified amount does not match the order does not mark it paid', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order'], amountKobo: 100); // ₦1 against a ₦20,000 order
    Log::spy();

    postPaystackWebhook($c['order'])->assertOk();

    expect($c['order']->fresh()->status)->toBe('pending')
        ->and(reservationCount($c['order']))->toBe(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'does not match the order'))
        ->once();
});

test('a signed webhook in the wrong currency does not mark the order paid', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order'], currency: 'USD');

    postPaystackWebhook($c['order'])->assertOk();

    expect($c['order']->fresh()->status)->toBe('pending');
});

test('events other than charge.success are acknowledged and ignored', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order']);

    postPaystackWebhook($c['order'], event: 'transfer.success')->assertOk();

    expect($c['order']->fresh()->status)->toBe('pending');
    Http::assertNothingSent();
});

test('callback then webhook confirms once: one transition, one reservation, one set of messages', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order']);

    test()->get(route('payment.callback', ['reference' => $c['order']->reference]));
    postPaystackWebhook($c['order'])->assertOk()->assertJson(['status' => 'already']);

    $order = $c['order']->fresh();

    expect($order->status)->toBe('paid')
        ->and(reservationCount($order))->toBe(1)
        ->and(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')->count())->toBe(1)
        ->and(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'storekeeper')->count())->toBe(1)
        ->and($order->activities()->where('properties->attributes->status', 'paid')->count())->toBe(1);
});

test('webhook then callback confirms once: one transition, one reservation, one set of messages', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order']);

    postPaystackWebhook($c['order'])->assertOk()->assertJson(['status' => 'paid']);

    test()->get(route('payment.callback', ['reference' => $c['order']->reference]))
        ->assertRedirect(route('checkout'));

    $order = $c['order']->fresh();

    expect($order->status)->toBe('paid')
        ->and(reservationCount($order))->toBe(1)
        ->and(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')->count())->toBe(1)
        ->and(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'storekeeper')->count())->toBe(1)
        ->and($order->activities()->where('properties->attributes->status', 'paid')->count())->toBe(1);
});

test('the webhook route is exempt from CSRF', function () {
    $c = paystackOrder();
    fakePaystackVerify($c['order']);

    // A 419 here would mean the CSRF middleware ran before the signature check.
    postPaystackWebhook($c['order'])->assertOk();
});

test('when Paystack cannot be reached the webhook asks to be retried and nothing changes', function () {
    $c = paystackOrder();
    Http::fake(['api.paystack.co/*' => Http::response([], 500)]);

    postPaystackWebhook($c['order'])->assertStatus(503);

    expect($c['order']->fresh()->status)->toBe('pending');
});
