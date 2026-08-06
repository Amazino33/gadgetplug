<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateLevel;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\AffiliateLevelProgressionService;

function makeProgressionAffiliate(): Affiliate
{
    return Affiliate::findOrCreateForUser(User::factory()->create());
}

function makeProgressionLevels(): array
{
    return [
        'bronze' => AffiliateLevel::create(['name' => 'Bronze', 'target' => 0,      'rate_value' => 1.0, 'sort_order' => 0]),
        'silver' => AffiliateLevel::create(['name' => 'Silver', 'target' => 50000,  'rate_value' => 1.1, 'sort_order' => 1]),
        'gold'   => AffiliateLevel::create(['name' => 'Gold',   'target' => 200000, 'rate_value' => 1.2, 'sort_order' => 2]),
    ];
}

// Creates one 'available' commission worth $baseAmount of lifetime sales value
// for the affiliate (rate/amount don't matter for progression, only base_amount).
function makeAvailableSale(Affiliate $affiliate, float $baseAmount): AffiliateCommission
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Progression Store ' . uniqid()]);
    $category = Category::create(['name' => 'Progression Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Progression Product',
        'price'          => $baseAmount,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => $baseAmount,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    $orderItem = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => $baseAmount,
    ]);

    $commission = AffiliateCommission::create([
        'affiliate_id' => $affiliate->id,
        'order_id'     => $order->id,
        'amount'       => 0,
        'status'       => 'available',
        'available_at' => now(),
    ]);

    $commission->items()->create([
        'order_item_id' => $orderItem->id,
        'rate'           => 10,
        'base_amount'    => $baseAmount,
        'amount'         => $baseAmount * 0.1,
    ]);

    return $commission;
}

test('lifetime sales value sums only base_amount from available commissions', function () {
    $affiliate = makeProgressionAffiliate();

    makeAvailableSale($affiliate, 1000);
    makeAvailableSale($affiliate, 2000);

    $pending = makeAvailableSale($affiliate, 5000);
    $pending->update(['status' => 'pending']);

    expect(app(AffiliateLevelProgressionService::class)->lifetimeSalesValue($affiliate->id))->toBe(3000.0);
});

test('an affiliate is promoted to the highest level their lifetime value qualifies for', function () {
    makeProgressionLevels();
    $affiliate = makeProgressionAffiliate();

    makeAvailableSale($affiliate, 60000);

    app(AffiliateLevelProgressionService::class)->recompute($affiliate);

    expect($affiliate->fresh('level')->level->name)->toBe('Silver');
});

test('crossing a higher threshold in one recompute jumps straight to that level', function () {
    makeProgressionLevels();
    $affiliate = makeProgressionAffiliate();

    makeAvailableSale($affiliate, 250000);

    app(AffiliateLevelProgressionService::class)->recompute($affiliate);

    expect($affiliate->fresh('level')->level->name)->toBe('Gold');
});

test('a small first sale promotes to the entry-level tier, not Silver or Gold', function () {
    makeProgressionLevels();
    $affiliate = makeProgressionAffiliate();

    makeAvailableSale($affiliate, 100);

    app(AffiliateLevelProgressionService::class)->recompute($affiliate);

    expect($affiliate->fresh('level')->level->name)->toBe('Bronze');
});

test('ratchet: a later drop in recomputed lifetime value never demotes an already-earned level', function () {
    makeProgressionLevels();
    $affiliate = makeProgressionAffiliate();

    $sale = makeAvailableSale($affiliate, 60000);
    $progression = app(AffiliateLevelProgressionService::class);
    $progression->recompute($affiliate);

    expect($affiliate->fresh('level')->level->name)->toBe('Silver');

    // Simulate the sale being rejected after the fact — pulls recomputed
    // lifetime value back under Silver's target.
    $sale->update(['status' => 'rejected']);
    expect($progression->lifetimeSalesValue($affiliate->id))->toBe(0.0);

    $progression->recompute($affiliate->fresh());

    expect($affiliate->fresh('level')->level->name)->toBe('Silver');
});

test('recompute does nothing when no active level exists yet', function () {
    $affiliate = makeProgressionAffiliate();

    makeAvailableSale($affiliate, 1000000);

    app(AffiliateLevelProgressionService::class)->recompute($affiliate);

    expect($affiliate->fresh()->affiliate_level_id)->toBeNull();
});
