<?php

use App\Actions\Inventory\ReserveStockAction;
use App\Jobs\SendMetaConversionEventJob;
use App\Models\Affiliate;
use App\Models\Checkout;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Affiliate\ResellerCheckoutService;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// One customer checkout → one `checkouts` row (customer, address, payment,
// reference, grand total) → one child Order per vendor in the basket, each with
// only that vendor's lines and subtotal.

const SPLIT_PAYSTACK_SECRET = 'sk_test_split_secret';

function splitProduct(Vendor $vendor, float $price, int $stock = 10, string $name = ''): Product
{
    return ProductFactory::new()->withPhoto()->create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'name'           => $name ?: 'Split Product '.uniqid(),
        'price'          => $price,
        'cost_price'     => $price * 0.6,
        'stock_quantity' => $stock,
    ]);
}

/** @param array<int, array{0: Product, 1: int}> $lines */
function splitCheckoutForm(array $lines, string $method = 'pay_on_delivery'): \Livewire\Features\SupportTesting\Testable
{
    Session::forget('payment_success');
    Session::put('cart', collect($lines)->mapWithKeys(fn ($l) => [$l[0]->id => ['quantity' => $l[1], 'max' => 10]])->all());

    return Volt::test('checkout')
        ->set('name', 'Ada Split')
        ->set('email', 'ada@example.com')
        ->set('phone', '08057189760')
        ->set('stateId', checkoutLocation()[0])->set('lgaId', checkoutLocation()[1])
        ->set('address', '12 Test Close, enough characters')
        ->set('paymentMethod', $method);
}

function twoVendorBasket(): array
{
    $a = Vendor::factory()->onlineSalesEnabled()->create(['name' => 'Alpha Gadgets']);
    $b = Vendor::factory()->onlineSalesEnabled()->create(['name' => 'Beta Phones']);

    return [
        'a'  => $a,
        'b'  => $b,
        'a1' => splitProduct($a, 10000),
        'a2' => splitProduct($a, 2500),
        'b1' => splitProduct($b, 40000),
    ];
}

function paystackVerifies(Checkout $checkout, ?int $kobo = null): void
{
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data'   => [
                'status'    => 'success',
                'reference' => $checkout->reference,
                'amount'    => $kobo ?? (int) round((float) $checkout->grand_total * 100),
                'currency'  => 'NGN',
            ],
        ]),
    ]);
}

describe('placing a checkout', function () {
    test('a mixed basket becomes one checkout and one order per vendor, each with only its own lines', function () {
        $p = twoVendorBasket();

        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1], [$p['a2'], 2]])->call('processCheckout');

        $checkout = Checkout::sole();
        $orders = $checkout->orders()->with('items')->get();

        expect($orders)->toHaveCount(2)
            ->and((float) $checkout->items_total)->toBe(55000.0)
            ->and((float) $checkout->delivery_fee)->toBe(0.0)
            ->and((float) $checkout->grand_total)->toBe(55000.0)
            // Uyo is free on day one (DeliveryRulesSeeder).
            ->and([$checkout->state_id, $checkout->lga_id])->toBe(checkoutLocation())
            ->and($checkout->delivery_rule_snapshot['mode'])->toBe('free');

        $alpha = $orders->firstWhere(fn ($o) => $o->items->first()->vendor_id === $p['a']->id);
        $beta = $orders->firstWhere(fn ($o) => $o->items->first()->vendor_id === $p['b']->id);

        expect($alpha->items->pluck('product_id')->sort()->values()->all())->toBe(collect([$p['a1']->id, $p['a2']->id])->sort()->values()->all())
            ->and((float) $alpha->total_amount)->toBe(15000.0)
            ->and($beta->items->pluck('product_id')->all())->toBe([$p['b1']->id])
            ->and((float) $beta->total_amount)->toBe(40000.0)
            ->and($orders->pluck('reference')->all())->toBe([$checkout->reference.'-1', $checkout->reference.'-2'])
            ->and($orders->pluck('status')->unique()->all())->toBe(['confirmed']);

        // Each vendor's stock is held against its own order's lines.
        expect(InventoryLedger::where('transaction_type', 'reserved')->where('reference', $alpha->reference)->count())->toBe(2)
            ->and(InventoryLedger::where('transaction_type', 'reserved')->where('reference', $beta->reference)->count())->toBe(1);
    });

    test('a single-vendor basket keeps the familiar shape: one order sharing the checkout reference', function () {
        $vendor = Vendor::factory()->onlineSalesEnabled()->create();
        $product = splitProduct($vendor, 7000);

        splitCheckoutForm([[$product, 2]])->call('processCheckout');

        $checkout = Checkout::sole();
        $order = Order::sole();

        expect($order->checkout_id)->toBe($checkout->id)
            ->and($order->reference)->toBe($checkout->reference)
            ->and((float) $order->total_amount)->toBe(14000.0)
            ->and((float) $checkout->grand_total)->toBe(14000.0)
            ->and($order->status)->toBe('confirmed')
            ->and(ProductStoreStock::where('product_id', $product->id)->value('reserved'))->toBe(2)
            ->and(session('payment_success'))->toBe($checkout->reference);
    });

    test('when one line cannot be reserved, nothing is held and no checkout is left behind', function () {
        $p = twoVendorBasket();
        $short = splitProduct($p['b'], 5000, stock: 1);

        splitCheckoutForm([[$p['a1'], 1], [$short, 1]])->set('paymentMethod', 'pay_on_delivery');

        // Someone else takes the last unit between the cart and Place Order.
        app(ReserveStockAction::class)->execute(productId: $short->id, quantity: 1);

        splitCheckoutForm([[$p['a1'], 1], [$short, 1]])->call('processCheckout');

        expect(Checkout::count())->toBe(0)
            ->and(Order::count())->toBe(0)
            // Alpha's line had been reserved before Beta's failed: rolled back.
            ->and(ProductStoreStock::where('product_id', $p['a1']->id)->value('reserved'))->toBe(0);
    });
});

describe('paying with Paystack', function () {
    test('Paystack charges the checkout once; success marks every child order paid, exactly once', function () {
        config(['services.paystack.secret_key' => SPLIT_PAYSTACK_SECRET]);
        $p = twoVendorBasket();

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1]], 'paystack')->call('processCheckout');

        $checkout = Checkout::sole();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'transaction/initialize')
            && $request['reference'] === $checkout->reference
            && $request['amount'] === 5000000);

        expect($checkout->orders()->pluck('status')->unique()->all())->toBe(['pending']);

        paystackVerifies($checkout);

        $this->get(route('payment.callback', ['reference' => $checkout->reference]))->assertRedirect(route('checkout'));

        // The webhook arriving second changes nothing.
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $checkout->reference]]);
        $this->call('POST', '/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, SPLIT_PAYSTACK_SECRET),
        ], $raw)->assertOk()->assertJson(['status' => 'already']);

        expect($checkout->orders()->pluck('status')->unique()->all())->toBe(['paid'])
            ->and(InventoryLedger::where('transaction_type', 'reserved')->count())->toBe(2);
    });

    test('Paystack must have taken the checkout\'s grand total, not one order\'s', function () {
        config(['services.paystack.secret_key' => SPLIT_PAYSTACK_SECRET]);
        $p = twoVendorBasket();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1]], 'paystack')->call('processCheckout');
        $checkout = Checkout::sole();

        paystackVerifies($checkout, kobo: 1000000); // only Alpha's ₦10,000

        $this->get(route('payment.callback', ['reference' => $checkout->reference]));

        expect($checkout->orders()->pluck('status')->unique()->all())->toBe(['pending']);
    });
});

describe('Meta Purchase', function () {
    test('fires once per checkout, under the checkout reference, for the whole basket', function () {
        Queue::fake();
        $p = twoVendorBasket();

        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1]])->call('processCheckout');
        $checkout = Checkout::sole();

        $purchases = collect(Queue::pushed(SendMetaConversionEventJob::class))
            ->map(fn ($job) => $job->payload()['data'][0])
            ->where('event_name', 'Purchase');

        expect($purchases)->toHaveCount(1)
            ->and($purchases->first()['event_id'])->toBe($checkout->reference)
            ->and($purchases->first()['custom_data']['value'])->toBe(50000.0)
            ->and($purchases->first()['custom_data']['content_ids'])->toEqualCanonicalizing([$p['a1']->id, $p['b1']->id])
            ->and($checkout->fresh()->purchase_tracked_at)->not->toBeNull();
    });
});

describe('what the customer sees', function () {
    test('/track by the checkout reference shows each vendor order as its own part', function () {
        $p = twoVendorBasket();
        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1]])->call('processCheckout');
        $checkout = Checkout::sole();

        $this->get(route('track-order', ['ref' => $checkout->reference]))
            ->assertOk()
            ->assertSee($checkout->reference)
            ->assertSee('Part 1 of 2')
            ->assertSee('Part 2 of 2')
            ->assertSee('Alpha Gadgets')
            ->assertSee('Beta Phones')
            ->assertSee('₦50,000');

        // A child order's own reference leads to the same checkout.
        $this->get(route('track-order', ['ref' => $checkout->reference.'-2']))
            ->assertSee('Part 2 of 2');
    });

    test('the confirmation screen lists the whole basket and its grand total', function () {
        $p = twoVendorBasket();
        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1]])->call('processCheckout');
        $checkout = Checkout::sole();

        Session::put('payment_success', $checkout->reference);
        $screen = Volt::test('checkout');

        expect($screen->get('paidReference'))->toBe($checkout->reference)
            ->and($screen->get('paidTotal'))->toBe(50000.0)
            ->and($screen->get('paidItems'))->toHaveCount(2);
    });

    test('account order history lists one row per checkout', function () {
        $user = User::factory()->create();
        $this->actingAs($user);
        $p = twoVendorBasket();
        splitCheckoutForm([[$p['a1'], 1], [$p['b1'], 1]])->call('processCheckout');
        $checkout = Checkout::sole();

        expect($checkout->user_id)->toBe($user->id);

        $this->get(route('account.orders'))
            ->assertOk()
            ->assertSee($checkout->reference)
            ->assertDontSee($checkout->reference.'-1')
            ->assertSee('₦50,000');
    });
});

describe('reseller wallet purchases', function () {
    test('go through the same flow: one checkout, one order and one wallet debit per vendor', function () {
        $p = twoVendorBasket();
        $user = User::factory()->create(['phone' => '08040000000']);
        $affiliate = Affiliate::findOrCreateForUser($user);
        WalletTransaction::create(['affiliate_id' => $affiliate->id, 'type' => 'credit', 'amount' => 100000, 'description' => 'Top-up']);

        $checkout = app(ResellerCheckoutService::class)->purchase(
            $affiliate,
            [['product' => $p['a1'], 'quantity' => 1], ['product' => $p['b1'], 'quantity' => 1]],
            'Uyo, Akwa Ibom State — Reseller Address',
        );

        expect($checkout)->toBeInstanceOf(Checkout::class)
            ->and($checkout->payment_method)->toBe('wallet')
            ->and($checkout->orders)->toHaveCount(2)
            ->and($checkout->orders->pluck('status')->unique()->all())->toBe(['confirmed'])
            ->and(WalletTransaction::where('type', 'debit')->count())->toBe(2)
            ->and(round((float) -WalletTransaction::where('type', 'debit')->sum('amount'), 2))->toBe((float) $checkout->grand_total);
    });
});

describe('historical orders', function () {
    test('the backfill gives every existing order its own checkout under the same reference, and normalises pod', function () {
        $vendor = Vendor::factory()->onlineSalesEnabled()->create();
        $product = splitProduct($vendor, 8000);

        $legacy = Order::create([
            'reference' => 'GP-LEGACY01', 'customer_name' => 'Old Customer',
            'customer_email' => 'old@example.com', 'customer_phone' => '08011112222',
            'shipping_address' => 'Uyo', 'local_government' => 'Uyo', 'total_amount' => 8000,
            'status' => 'delivered', 'payment_method' => 'pod',
        ]);
        OrderItem::create(['order_id' => $legacy->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id, 'quantity' => 1, 'unit_price' => 8000]);

        (require database_path('migrations/2026_10_18_100001_backfill_checkouts_from_orders.php'))->up();

        $legacy->refresh();
        $checkout = Checkout::findByReference('GP-LEGACY01');

        expect($checkout)->not->toBeNull()
            ->and($legacy->checkout_id)->toBe($checkout->id)
            ->and($checkout->reference)->toBe('GP-LEGACY01')
            ->and((float) $checkout->grand_total)->toBe(8000.0)
            ->and($legacy->payment_method)->toBe('pay_on_delivery')
            ->and($checkout->payment_method)->toBe('pay_on_delivery')
            // Already past "settled": its Purchase went out long ago, so it is
            // marked and can never be re-sent.
            ->and($checkout->purchase_tracked_at)->not->toBeNull();

        $this->get(route('track-order', ['ref' => 'GP-LEGACY01']))->assertOk()->assertSee('GP-LEGACY01');
    });
});
