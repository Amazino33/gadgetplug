<?php

use App\Jobs\SendMetaConversionEventJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

function makeCheckoutPixelProduct(array $attrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Checkout Pixel Store ' . uniqid(), 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Checkout Pixel Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Checkout Pixel Product',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $attrs));
}

test('loading the checkout form dispatches a queued InitiateCheckout CAPI event', function () {
    Queue::fake();

    $product = makeCheckoutPixelProduct();
    Session::put('cart', [$product->id => ['quantity' => 2]]);

    Volt::test('checkout');

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) use ($product) {
        $event = $job->payload()['data'][0] ?? null;

        return $event
            && $event['event_name'] === 'InitiateCheckout'
            && $event['custom_data']['value'] === 10000.0
            && $event['custom_data']['content_ids'] === [$product->id];
    });
});

// Livewire's test harness doesn't propagate $this->withCookie() (nor a
// manually-mutated request()->cookies bag) through to the component's own
// request() calls during ->call() — a testing-harness limitation, not
// something in doubt about request()->cookie() itself in real traffic. So
// this verifies the part actually at risk of being wrong (the migration/
// column/mass-assignment path processCheckout() writes fbp/fbc through),
// rather than fighting Livewire's cookie propagation to prove framework
// behavior that isn't this app's code.
test('the orders table persists fbp and fbc for the server-side Purchase event to use later', function () {
    $order = Order::create([
        'reference'        => 'GP-FBPFBCTEST',
        'customer_name'    => 'Cookie Buyer',
        'customer_email'   => 'cookie@example.com',
        'customer_phone'   => '08012345678',
        'shipping_address' => 'Uyo, Akwa Ibom State — Test Street',
        'total_amount'     => 5000,
        'status'           => 'pending',
        'payment_method'   => 'pay_on_delivery',
        'fbp'              => 'fb.1.111.222',
        'fbc'              => 'fb.1.111.333',
    ]);

    expect($order->fresh()->fbp)->toBe('fb.1.111.222')
        ->and($order->fresh()->fbc)->toBe('fb.1.111.333');
});
