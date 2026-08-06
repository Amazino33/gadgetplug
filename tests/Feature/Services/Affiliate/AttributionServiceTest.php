<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\AttributionService;

function makeAttributionAffiliate(?string $email = null): Affiliate
{
    $user = User::factory()->create($email ? ['email' => $email] : []);

    return Affiliate::findOrCreateForUser($user);
}

function makeAttributionOrder(?int $userId = null, ?string $email = null): Order
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Attribution Store ' . uniqid()]);
    $category = Category::create(['name' => 'Attribution Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Attribution Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'user_id'          => $userId,
        'customer_name'    => 'Test Buyer',
        'customer_email'   => $email ?? 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => 1000,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]);

    return $order->fresh('items.product.category');
}

test('a valid checkout-entered code wins over a cookie from a different affiliate', function () {
    $checkoutAffiliate = makeAttributionAffiliate();
    $cookieAffiliate   = makeAttributionAffiliate();
    $order             = makeAttributionOrder();

    $commission = app(AttributionService::class)->attributeOrder(
        $order,
        $checkoutAffiliate->code,
        $cookieAffiliate->code,
    );

    expect($commission->affiliate_id)->toBe($checkoutAffiliate->id);
});

test('the cookie is used when no code is entered at checkout', function () {
    $cookieAffiliate = makeAttributionAffiliate();
    $order           = makeAttributionOrder();

    $commission = app(AttributionService::class)->attributeOrder($order, null, $cookieAffiliate->code);

    expect($commission->affiliate_id)->toBe($cookieAffiliate->id);
});

test('an invalid entered code falls back to a valid cookie rather than blocking attribution', function () {
    $cookieAffiliate = makeAttributionAffiliate();
    $order           = makeAttributionOrder();

    $commission = app(AttributionService::class)->attributeOrder($order, 'NOT-A-REAL-CODE', $cookieAffiliate->code);

    expect($commission->affiliate_id)->toBe($cookieAffiliate->id);
});

test('an inactive affiliate code produces no attribution', function () {
    $affiliate = makeAttributionAffiliate();
    $affiliate->update(['is_active' => false]);
    $order = makeAttributionOrder();

    $commission = app(AttributionService::class)->attributeOrder($order, $affiliate->code, null);

    expect($commission)->toBeNull()
        ->and(AffiliateCommission::where('order_id', $order->id)->exists())->toBeFalse();
});

test('an affiliate cannot earn commission on their own logged-in order', function () {
    $user      = User::factory()->create();
    $affiliate = Affiliate::findOrCreateForUser($user);
    $order     = makeAttributionOrder(userId: $user->id);

    $commission = app(AttributionService::class)->attributeOrder($order, $affiliate->code, null);

    expect($commission)->toBeNull()
        ->and(AffiliateCommission::where('order_id', $order->id)->exists())->toBeFalse();
});

test('an affiliate cannot earn commission on a guest order using their own email', function () {
    $affiliate = makeAttributionAffiliate(email: 'selfref@example.com');
    $order     = makeAttributionOrder(email: 'selfref@example.com');

    $commission = app(AttributionService::class)->attributeOrder($order, $affiliate->code, null);

    expect($commission)->toBeNull();
});

test('self-referral email comparison is case-insensitive', function () {
    $affiliate = makeAttributionAffiliate(email: 'SelfRef@Example.com');
    $order     = makeAttributionOrder(email: 'selfref@example.com');

    $commission = app(AttributionService::class)->attributeOrder($order, $affiliate->code, null);

    expect($commission)->toBeNull();
});

test('a different affiliate still earns commission on an order placed by another user', function () {
    $affiliate = makeAttributionAffiliate();
    $order     = makeAttributionOrder(email: 'someone-else@example.com');

    $commission = app(AttributionService::class)->attributeOrder($order, $affiliate->code, null);

    expect($commission)->not->toBeNull()
        ->and($commission->affiliate_id)->toBe($affiliate->id);
});

test('no code and no cookie produces no attribution', function () {
    $order = makeAttributionOrder();

    $commission = app(AttributionService::class)->attributeOrder($order, null, null);

    expect($commission)->toBeNull();
});
