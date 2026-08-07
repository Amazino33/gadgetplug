<?php

use App\Jobs\SendMetaConversionEventJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Queue;

function makeOrderPurchaseTestOrder(string $paymentMethod, array $orderAttrs = []): Order
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Order Purchase Store ' . uniqid()]);
    $category = Category::create(['name' => 'Order Purchase Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Order Purchase Product',
        'price'          => 6000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create(array_merge([
        'reference'        => 'GP-' . strtoupper(uniqid()),
        'customer_name'    => 'Order Purchase Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08012345678',
        'shipping_address' => 'Uyo, Akwa Ibom State — Test Street',
        'total_amount'     => 6000,
        'status'           => 'pending',
        'payment_method'   => $paymentMethod,
        'fbp'              => 'fb.1.999.111',
        'fbc'              => 'fb.1.999.222',
    ], $orderAttrs));

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 6000,
    ]);

    return $order;
}

test('a pay-on-delivery order moving to confirmed fires both Purchase and PaymentConfirmed', function () {
    Queue::fake();

    $order = makeOrderPurchaseTestOrder('pay_on_delivery');
    $order->update(['status' => 'confirmed']);

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) use ($order) {
        $event = $job->payload()['data'][0] ?? null;

        return $event
            && $event['event_name'] === 'Purchase'
            && $event['event_id'] === $order->reference
            && $event['custom_data']['value'] === 6000.0
            && $event['user_data']['fbp'] === 'fb.1.999.111';
    });

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) use ($order) {
        $event = $job->payload()['data'][0] ?? null;

        return $event
            && $event['event_name'] === 'PaymentConfirmed'
            && $event['event_id'] === $order->reference . '-payment-confirmed';
    });
});

test('a Paystack order moving to paid fires Purchase only, never PaymentConfirmed', function () {
    Queue::fake();

    $order = makeOrderPurchaseTestOrder('paystack');
    $order->update(['status' => 'paid']);

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) {
        return ($job->payload()['data'][0]['event_name'] ?? null) === 'Purchase';
    });

    Queue::assertNotPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) {
        return ($job->payload()['data'][0]['event_name'] ?? null) === 'PaymentConfirmed';
    });
});

test('an order moving to shipped fires no additional Purchase — it already fired at confirmed', function () {
    Queue::fake();

    $order = makeOrderPurchaseTestOrder('pay_on_delivery');
    $order->update(['status' => 'confirmed']);

    Queue::fake(); // reset the count captured above

    $order->update(['status' => 'shipped']);

    Queue::assertNotPushed(SendMetaConversionEventJob::class);
});

test('re-saving an order without changing its status never re-fires Purchase', function () {
    $order = makeOrderPurchaseTestOrder('pay_on_delivery');
    $order->update(['status' => 'confirmed']);

    Queue::fake();

    $order->update(['customer_name' => 'Renamed Buyer']);

    Queue::assertNotPushed(SendMetaConversionEventJob::class);
});
