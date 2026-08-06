<?php

use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\CommissionService;

function makeBoostAffiliate(?AffiliateLevel $level = null): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    if ($level) {
        $affiliate->update(['affiliate_level_id' => $level->id, 'level_achieved_at' => now()]);
    }

    return $affiliate->fresh('level');
}

function makeBoostProduct(array $attrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Boost Store ' . uniqid()]);
    $category = Category::create(['name' => 'Boost Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Boost Product',
        'price'          => 1000,
        'stock_quantity' => 50,
        'status'         => 'published',
    ], $attrs));
}

function makeBoostOrder(Product $product, int $quantity, float $unitPrice, ?float $unitCost = null): Order
{
    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => $quantity * $unitPrice,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => $quantity,
        'unit_price' => $unitPrice,
        'unit_cost'  => $unitCost,
    ]);

    return $order->fresh('items.product.category');
}

test('a normal-margin item gets the full boosted commission when it stays under the margin cap', function () {
    AffiliateSetting::current()->update(['margin_cap_fraction' => 0.35]);

    $level     = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.2, 'sort_order' => 1]);
    $affiliate = makeBoostAffiliate($level);

    $product = makeBoostProduct(['commission_rate' => 20.0]);
    $order   = makeBoostOrder($product, quantity: 1, unitPrice: 1000, unitCost: 100); // margin = 900, cap = 315

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);

    // boosted amount = 24% of 1000 = 240; cap = 35% of (1000-100) = 315; 240 < 315, uncapped
    expect($commission->amount)->toEqual('240.00');
});

test('a thin-margin item has its boosted commission capped at the configured fraction of margin', function () {
    AffiliateSetting::current()->update(['margin_cap_fraction' => 0.35]);

    $level     = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.2, 'sort_order' => 1]);
    $affiliate = makeBoostAffiliate($level);

    $product = makeBoostProduct(['commission_rate' => 20.0]);
    // boosted amount = 24% of 1000 = 240; margin = 1000-950 = 50; cap = 35% of 50 = 17.50
    $order = makeBoostOrder($product, quantity: 1, unitPrice: 1000, unitCost: 950);

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);

    expect($commission->amount)->toEqual('17.50');
});

test('when margin data is unavailable, no level boost applies and the plain base rate is used', function () {
    $level     = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.5, 'sort_order' => 1]);
    $affiliate = makeBoostAffiliate($level);

    $product = makeBoostProduct(['commission_rate' => 10.0]); // no cost_price set
    $order   = makeBoostOrder($product, quantity: 1, unitPrice: 1000, unitCost: null);

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);

    // No boost applied since margin is unknowable — plain 10% of 1000 = 100.
    expect($commission->amount)->toEqual('100.00')
        ->and((float) $commission->items->first()->rate)->toBe(10.0);
});

test('an affiliate with no level gets no boost and no cap applied at all', function () {
    $affiliate = makeBoostAffiliate();

    $product = makeBoostProduct(['commission_rate' => 20.0]);
    $order   = makeBoostOrder($product, quantity: 1, unitPrice: 1000, unitCost: 950); // tiny margin, but no level -> no cap

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);

    expect($commission->amount)->toEqual('200.00'); // plain 20% of 1000, uncapped
});

test('the level factor is frozen at order time — a later level rate change never rewrites an existing commission', function () {
    $level     = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.2, 'sort_order' => 1]);
    $affiliate = makeBoostAffiliate($level);

    $product = makeBoostProduct(['commission_rate' => 10.0]);
    $order   = makeBoostOrder($product, quantity: 1, unitPrice: 1000, unitCost: 100);

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);
    expect($commission->amount)->toEqual('120.00'); // 10% * 1.2 = 12% of 1000

    $level->update(['rate_value' => 5.0]);

    expect($commission->fresh()->amount)->toEqual('120.00');
});
