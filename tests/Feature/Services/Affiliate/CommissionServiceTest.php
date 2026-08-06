<?php

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Affiliate\CommissionService;
use App\Services\Affiliate\WalletService;

function makeCommissionAffiliate(): Affiliate
{
    $user = User::factory()->create();

    return Affiliate::findOrCreateForUser($user);
}

function makeCommissionOrder(array $lines, ?int $orderUserId = null, ?string $customerEmail = null): Order
{
    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'user_id'          => $orderUserId,
        'customer_name'    => 'Test Buyer',
        'customer_email'   => $customerEmail ?? 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => collect($lines)->sum(fn ($l) => $l['unit_price'] * $l['quantity']),
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    foreach ($lines as $line) {
        OrderItem::create(array_merge([
            'order_id' => $order->id,
        ], $line));
    }

    return $order->fresh('items.product.category');
}

function makeCommissionProduct(?float $rate = null): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Commission Store ' . uniqid()]);
    $category = Category::create(['name' => 'Commission Category ' . uniqid()]);

    return Product::create([
        'vendor_id'        => $vendor->id,
        'category_id'      => $category->id,
        'name'             => 'Commission Product',
        'price'            => 1000,
        'stock_quantity'   => 50,
        'status'           => 'published',
        'commission_rate'  => $rate,
    ]);
}

test('commission base is the sum of order item quantity times unit price', function () {
    $affiliate = makeCommissionAffiliate();
    $product   = makeCommissionProduct(rate: 10.0);
    $order     = makeCommissionOrder([[
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 3,
        'unit_price' => 1000,
    ]]);

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);

    // base = 3 * 1000 = 3000; amount = 10% of 3000 = 300
    expect($commission->amount)->toEqual('300.00')
        ->and($commission->items->first()->base_amount)->toEqual('3000.00');
});

test('a multi-product order resolves and freezes a different rate per line', function () {
    $affiliate = makeCommissionAffiliate();
    $productA  = makeCommissionProduct(rate: 10.0);
    $productB  = makeCommissionProduct(rate: 20.0);

    $order = makeCommissionOrder([
        ['product_id' => $productA->id, 'vendor_id' => $productA->vendor_id, 'quantity' => 1, 'unit_price' => 1000],
        ['product_id' => $productB->id, 'vendor_id' => $productB->vendor_id, 'quantity' => 1, 'unit_price' => 2000],
    ]);

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);

    // line A: 10% of 1000 = 100; line B: 20% of 2000 = 400; total = 500
    expect($commission->items)->toHaveCount(2)
        ->and($commission->amount)->toEqual('500.00');

    $rates = $commission->items->pluck('rate')->map(fn ($r) => (float) $r)->sort()->values()->all();
    expect($rates)->toBe([10.0, 20.0]);
});

test('re-creating a commission for the same order is idempotent', function () {
    $affiliate = makeCommissionAffiliate();
    $product   = makeCommissionProduct(rate: 10.0);
    $order     = makeCommissionOrder([[
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]]);

    $service = app(CommissionService::class);
    $first  = $service->createForOrder($order, $affiliate);
    $second = $service->createForOrder($order, $affiliate);

    expect($first->id)->toBe($second->id)
        ->and(AffiliateCommission::where('order_id', $order->id)->count())->toBe(1);
});

test('a settings rate change never rewrites an already-frozen commission', function () {
    \App\Models\AffiliateSetting::current()->update(['platform_default_rate' => 5.0]);

    $affiliate = makeCommissionAffiliate();
    $product   = makeCommissionProduct(); // no override -> uses platform default (5%)
    $order     = makeCommissionOrder([[
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]]);

    $commission = app(CommissionService::class)->createForOrder($order, $affiliate);
    expect($commission->amount)->toEqual('50.00'); // 5% of 1000

    \App\Models\AffiliateSetting::current()->update(['platform_default_rate' => 50.0]);

    expect($commission->fresh()->amount)->toEqual('50.00');
});

test('the full lifecycle credits the ledger exactly once', function () {
    $affiliate = makeCommissionAffiliate();
    $product   = makeCommissionProduct(rate: 10.0);
    $order     = makeCommissionOrder([[
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]]);

    $service = app(CommissionService::class);
    $commission = $service->createForOrder($order, $affiliate);
    expect($commission->status)->toBe('pending');

    $service->startReturnWindow($order);
    expect($commission->fresh()->status)->toBe('return_window');

    // Simulate the hold job's own clearing logic directly (job itself is
    // tested separately with real time-based queries).
    $commission->fresh()->update(['status' => 'available', 'available_at' => now()]);
    $commission->walletTransactions()->create([
        'affiliate_id' => $affiliate->id,
        'type'         => 'credit',
        'amount'       => $commission->amount,
    ]);

    expect(WalletTransaction::where('affiliate_commission_id', $commission->id)->where('type', 'credit')->count())->toBe(1)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toEqual(100.0);
});

test('rejecting an already-credited commission writes a compensating reversal, never mutates the credit', function () {
    $affiliate = makeCommissionAffiliate();
    $product   = makeCommissionProduct(rate: 10.0);
    $order     = makeCommissionOrder([[
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]]);

    $service = app(CommissionService::class);
    $commission = $service->createForOrder($order, $affiliate);
    $commission->update(['status' => 'available', 'available_at' => now()]);
    $credit = $commission->walletTransactions()->create([
        'affiliate_id' => $affiliate->id,
        'type'         => 'credit',
        'amount'       => $commission->amount,
    ]);

    $service->reject($order, 'order_cancelled');

    expect($commission->fresh()->status)->toBe('rejected')
        ->and(WalletTransaction::find($credit->id)->amount)->toEqual('100.00') // original untouched
        ->and(WalletTransaction::where('affiliate_commission_id', $commission->id)->where('type', 'reversal')->count())->toBe(1)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toEqual(0.0);
});

test('rejecting a pending commission writes no reversal, since nothing was ever credited', function () {
    $affiliate = makeCommissionAffiliate();
    $product   = makeCommissionProduct(rate: 10.0);
    $order     = makeCommissionOrder([[
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]]);

    $service = app(CommissionService::class);
    $commission = $service->createForOrder($order, $affiliate);

    $service->reject($order, 'order_cancelled');

    expect($commission->fresh()->status)->toBe('rejected')
        ->and(WalletTransaction::where('affiliate_commission_id', $commission->id)->count())->toBe(0);
});

test('pending and available balances derive correctly across mixed commission states', function () {
    $affiliate = makeCommissionAffiliate();
    $service   = app(CommissionService::class);

    foreach ([
        ['status' => 'pending', 'amount' => 100],
        ['status' => 'return_window', 'amount' => 200],
        ['status' => 'available', 'amount' => 300],
        ['status' => 'rejected', 'amount' => 400],
    ] as $state) {
        $product = makeCommissionProduct(rate: 10.0);
        $order   = makeCommissionOrder([[
            'product_id' => $product->id,
            'vendor_id'  => $product->vendor_id,
            'quantity'   => 1,
            'unit_price' => $state['amount'] * 10, // so 10% commission = $state['amount']
        ]]);

        $commission = $service->createForOrder($order, $affiliate);
        $commission->update(['status' => $state['status']]);

        if ($state['status'] === 'available') {
            $commission->walletTransactions()->create([
                'affiliate_id' => $affiliate->id,
                'type'         => 'credit',
                'amount'       => $commission->amount,
            ]);
        }
    }

    $wallet = app(WalletService::class);

    // pending (100) + return_window (200) = 300; rejected excluded entirely
    expect($wallet->pendingBalance($affiliate->id))->toEqual(300.0)
        ->and($wallet->availableBalance($affiliate->id))->toEqual(300.0);
});
