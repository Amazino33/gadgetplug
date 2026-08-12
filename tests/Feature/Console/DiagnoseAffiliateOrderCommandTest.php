<?php

use App\Jobs\ClearAffiliateHoldsJob;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function makeDiagnoseOrder(?string $customerEmail = null, string $status = 'pending'): Order
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Diag Store '.uniqid()]);
    $category = Category::create(['name' => 'Diag Category '.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id, 'name' => 'Diag Product',
        'price' => 10000, 'cost_price' => 6000, 'stock_quantity' => 5, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'GP-DIAG-'.strtoupper(uniqid()),
        'customer_name' => 'Buyer', 'customer_email' => $customerEmail ?? 'buyer@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => 10000, 'status' => $status, 'payment_method' => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => 1, 'unit_price' => 10000, 'unit_cost' => 6000,
    ]);

    return $order->fresh('items.product.category');
}

function makeDiagnoseCommission(Order $order): AffiliateCommission
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    return app(CommissionService::class)->createForOrder($order, $affiliate);
}

test('it reports an unattributed order as attribution never happening', function () {
    $order = makeDiagnoseOrder();

    $this->artisan('affiliate:diagnose', ['order' => $order->reference])
        ->expectsOutputToContain('No commission was ever created')
        ->expectsOutputToContain('owns no affiliate account')
        ->assertSuccessful();
});

test('it names the self-referral when the customer is themselves an affiliate', function () {
    $affiliateUser = User::factory()->create(['email' => 'selfref@example.com']);
    $affiliate = Affiliate::findOrCreateForUser($affiliateUser);
    $order = makeDiagnoseOrder('selfref@example.com');

    // Artisan::call rather than the fluent expectsOutputToContain chain: the
    // affiliate code and the words "Self-referral" share one output line, and
    // two substring expectations matching the same write only ever satisfy one
    // of them. Asserting against the captured buffer checks both honestly.
    Artisan::call('affiliate:diagnose', ['order' => $order->reference]);
    $output = Artisan::output();

    expect($output)->toContain('Self-referral')
        ->and($output)->toContain($affiliate->code)
        ->and($output)->toContain('refused by design');
});

test('it explains a pending commission as waiting on the delivered status, not a fault', function () {
    $order = makeDiagnoseOrder();
    makeDiagnoseCommission($order);

    $this->artisan('affiliate:diagnose', ['order' => $order->reference])
        ->expectsOutputToContain('attribution worked')
        ->expectsOutputToContain('Still PENDING')
        ->expectsOutputToContain("currently 'pending'")
        ->assertSuccessful();
});

test('it reports a hold that is still running as nothing being broken', function () {
    $order = makeDiagnoseOrder();
    $commission = makeDiagnoseCommission($order);
    $commission->update(['status' => 'return_window', 'return_window_started_at' => now()]);

    $this->artisan('affiliate:diagnose', ['order' => $order->reference])
        ->expectsOutputToContain('RETURN WINDOW')
        ->expectsOutputToContain('Nothing is broken')
        ->assertSuccessful();
});

test('it flags an elapsed hold as the clearing job not running, and says how to check', function () {
    $order = makeDiagnoseOrder();
    $commission = makeDiagnoseCommission($order);
    $commission->update(['status' => 'return_window', 'return_window_started_at' => now()->subDays(30)]);

    $this->artisan('affiliate:diagnose', ['order' => $order->reference])
        ->expectsOutputToContain('OVERDUE')
        ->expectsOutputToContain('ClearAffiliateHoldsJob has not run')
        ->expectsOutputToContain('schedule:run')
        ->expectsOutputToContain('queue:work')
        ->assertSuccessful();
});

test('it confirms a cleared commission and shows the wallet credit behind it', function () {
    $order = makeDiagnoseOrder();
    $commission = makeDiagnoseCommission($order);
    $commission->update(['status' => 'return_window', 'return_window_started_at' => now()->subDays(30)]);

    (new ClearAffiliateHoldsJob)->handle();

    $this->artisan('affiliate:diagnose', ['order' => $order->reference])
        ->expectsOutputToContain('AVAILABLE')
        ->expectsOutputToContain('credit')
        ->assertSuccessful();
});

test('it reports a rejected commission with the reason it was rejected', function () {
    $order = makeDiagnoseOrder();
    $commission = makeDiagnoseCommission($order);
    app(CommissionService::class)->reject($order, 'order_cancelled');

    $this->artisan('affiliate:diagnose', ['order' => $commission->order_id])
        ->expectsOutputToContain('REJECTED')
        ->expectsOutputToContain('order_cancelled')
        ->assertSuccessful();
});

test('it fails cleanly on an order reference that does not exist', function () {
    $this->artisan('affiliate:diagnose', ['order' => 'GP-NOT-A-REAL-ORDER'])
        ->expectsOutputToContain('No order matching')
        ->assertFailed();
});
