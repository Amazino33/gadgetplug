<?php

use App\Jobs\ClearAffiliateHoldsJob;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;

function makeHoldJobCommission(string $status, ?\Carbon\CarbonInterface $returnWindowStartedAt = null): AffiliateCommission
{
    $owner    = User::factory()->create();
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Hold Job Store ' . uniqid()]);
    $category = Category::create(['name' => 'Hold Job Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Hold Job Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
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

    return AffiliateCommission::create([
        'affiliate_id'              => $affiliate->id,
        'order_id'                  => $order->id,
        'amount'                    => 100,
        'status'                    => $status,
        'return_window_started_at'  => $returnWindowStartedAt,
    ]);
}

test('a commission past its hold window clears to available and gets exactly one ledger credit', function () {
    AffiliateSetting::current()->update(['return_window_days' => 3]);

    $commission = makeHoldJobCommission('return_window', now()->subDays(4));

    (new ClearAffiliateHoldsJob())->handle();

    expect($commission->fresh()->status)->toBe('available')
        ->and(WalletTransaction::where('affiliate_commission_id', $commission->id)->where('type', 'credit')->count())->toBe(1);
});

test('a commission still inside its hold window is left alone', function () {
    AffiliateSetting::current()->update(['return_window_days' => 3]);

    $commission = makeHoldJobCommission('return_window', now()->subDay());

    (new ClearAffiliateHoldsJob())->handle();

    expect($commission->fresh()->status)->toBe('return_window')
        ->and(WalletTransaction::where('affiliate_commission_id', $commission->id)->count())->toBe(0);
});

test('running the hold job twice never double-credits the same commission', function () {
    AffiliateSetting::current()->update(['return_window_days' => 3]);

    $commission = makeHoldJobCommission('return_window', now()->subDays(4));

    (new ClearAffiliateHoldsJob())->handle();
    (new ClearAffiliateHoldsJob())->handle();

    expect(WalletTransaction::where('affiliate_commission_id', $commission->id)->count())->toBe(1);
});

test('a pending commission (not yet in the return window) is never touched by the hold job', function () {
    $commission = makeHoldJobCommission('pending');

    (new ClearAffiliateHoldsJob())->handle();

    expect($commission->fresh()->status)->toBe('pending')
        ->and(WalletTransaction::where('affiliate_commission_id', $commission->id)->count())->toBe(0);
});
