<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Affiliate\ResellerCheckoutService;
use App\Services\Affiliate\WalletService;

function makeResellerAffiliate(float $startingBalance = 0.0, ?string $phone = '08040000000'): Affiliate
{
    $user = User::factory()->create(['phone' => $phone]);
    $affiliate = Affiliate::findOrCreateForUser($user);

    if ($startingBalance != 0.0) {
        WalletTransaction::create([
            'affiliate_id' => $affiliate->id,
            'type'         => 'credit',
            'amount'       => $startingBalance,
            'description'  => 'Test seed credit',
        ]);
    }

    return $affiliate;
}

function makeResellerProduct(array $attrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Reseller Checkout Store ' . uniqid()]);
    $category = Category::create(['name' => 'Reseller Checkout Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Reseller Checkout Product',
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 50,
        'status'         => 'published',
    ], $attrs));
}

test('the discount is applied to the subtotal and the order total reflects it', function () {
    $affiliate = makeResellerAffiliate(1000);
    $product   = makeResellerProduct(['reseller_discount' => 20.0, 'price' => 1000]);

    $order = app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Test Address',
    );

    // 20% off 1000 = 800
    expect((float) $order->total_amount)->toBe(800.0)
        ->and((float) $order->items->first()->unit_price)->toBe(800.0)
        ->and($order->payment_method)->toBe('wallet')
        ->and($order->status)->toBe('confirmed');
});

test('the wallet is debited exactly the discounted total', function () {
    $affiliate = makeResellerAffiliate(1000);
    $product   = makeResellerProduct(['reseller_discount' => 20.0, 'price' => 1000]);

    app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Test Address',
    );

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(200.0);
});

test('a purchase is blocked when available balance is insufficient, and nothing is debited or created', function () {
    $affiliate = makeResellerAffiliate(100);
    $product   = makeResellerProduct(['reseller_discount' => 0.0, 'price' => 1000]);

    expect(fn () => app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Test Address',
    ))->toThrow(RuntimeException::class);

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(100.0)
        ->and(\App\Models\Order::where('payment_method', 'wallet')->count())->toBe(0);
});

test('a second purchase that would overdraw the balance is blocked even though the first one succeeded', function () {
    $affiliate = makeResellerAffiliate(1000);
    $product   = makeResellerProduct(['reseller_discount' => 0.0, 'price' => 700]);

    $service = app(ResellerCheckoutService::class);

    // First purchase: 700, leaves 300 available.
    $service->purchase($affiliate, [['product' => $product, 'quantity' => 1]], 'Uyo, Akwa Ibom State — Address 1');

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(300.0);

    // Second purchase would need another 700 — only 300 available, must be rejected.
    expect(fn () => $service->purchase($affiliate, [['product' => $product, 'quantity' => 1]], 'Uyo, Akwa Ibom State — Address 2'))
        ->toThrow(RuntimeException::class);

    expect(app(WalletService::class)->availableBalance($affiliate->id))->toBe(300.0);
});

test('a reseller purchase creates no affiliate commission', function () {
    $affiliate = makeResellerAffiliate(1000);
    $product   = makeResellerProduct(['reseller_discount' => 10.0]);

    $order = app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Test Address',
    );

    expect(AffiliateCommission::where('order_id', $order->id)->exists())->toBeFalse();
});

test('a reseller purchase reserves stock through the existing inventory pipeline', function () {
    $affiliate = makeResellerAffiliate(3000);
    $product   = makeResellerProduct(['reseller_discount' => 0.0, 'price' => 1000, 'stock_quantity' => 10]);

    app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 3]],
        'Uyo, Akwa Ibom State — Test Address',
    );

    expect($product->fresh()->reserved_stock)->toBe(3)
        ->and($product->fresh()->stock_quantity)->toBe(10); // physical stock untouched until shipped
});

test('pending balance is untouched by a reseller purchase — only available balance moves', function () {
    $affiliate = makeResellerAffiliate(1000);
    $product   = makeResellerProduct(['reseller_discount' => 0.0, 'price' => 400]);

    app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Test Address',
    );

    expect(app(WalletService::class)->pendingBalance($affiliate->id))->toBe(0.0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(600.0);
});

test('purchasing with no line items throws', function () {
    $affiliate = makeResellerAffiliate(1000);

    expect(fn () => app(ResellerCheckoutService::class)->purchase($affiliate, [], 'Uyo, Akwa Ibom State — Test Address'))
        ->toThrow(RuntimeException::class);
});

test('an affiliate with no phone number on file cannot check out', function () {
    $affiliate = makeResellerAffiliate(1000, phone: null);
    $product   = makeResellerProduct();

    expect(fn () => app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Test Address',
    ))->toThrow(RuntimeException::class);
});
