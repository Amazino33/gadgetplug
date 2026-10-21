<?php

use App\Actions\Inventory\ReserveStockAction;
use App\Filament\Resources\Orders\Pages\ViewOrder as AdminViewOrder;
use App\Filament\Vendor\Resources\Orders\Pages\ListOrders;
use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Jobs\ReleaseStaleReservationsJob;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayout;
use App\Services\Cash\StoreReconciliation;
use App\Services\FinancialLedger;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Phase 3: one service owns every online order status change. The store packs
// and hands over (stock leaves then); GadgetPlug records delivery, cancellation
// and the money it collected (revenue goes to the vendor's Due from
// GadgetPlug account then); delivered itself moves nothing.

function oss(): OrderStatusService
{
    return app(OrderStatusService::class);
}

/** A vendor with two branches; stock of one product at each. */
function ossVendor(): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'OSS Store '.uniqid(), 'online_sales_enabled' => true]);
    $main     = $vendor->defaultStore ?? Store::create(['vendor_id' => $vendor->id, 'name' => 'Main', 'is_default' => true]);
    $branch   = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $category = Category::create(['name' => 'OSS Category '.uniqid()]);
    $product  = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id, 'store_id' => $main->id,
        'name' => 'OSS Widget', 'price' => 5000, 'cost_price' => 3000,
        'stock_quantity' => 10, 'status' => 'published',
    ]);

    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $main->id], ['quantity' => 5, 'reserved' => 0]);
    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $branch->id], ['quantity' => 5, 'reserved' => 0]);

    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    return compact('owner', 'vendor', 'main', 'branch', 'product', 'admin');
}

/**
 * An order of $qty units reserved at $store, at 'pending' — then walked to
 * $status through the service as checkout/Paystack would.
 */
function ossOrder(array $c, string $method, string $status, int $qty = 2, ?Store $store = null): Order
{
    $order = Order::create([
        'reference' => 'OSS-'.uniqid(), 'customer_name' => 'Ada', 'customer_email' => 'ada@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => $qty * 5000, 'status' => 'pending', 'payment_method' => $method,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $c['product']->id, 'vendor_id' => $c['vendor']->id,
        'quantity' => $qty, 'unit_price' => 5000,
    ]);

    app(ReserveStockAction::class)->execute(
        productId: $c['product']->id, quantity: $qty, reference: $order->reference,
        orderItemId: $item->id, store: ($store ?? $c['main'])->id,
    );

    $order = $order->fresh();

    if ($status === 'pending') {
        return $order;
    }

    $method === 'paystack' ? oss()->markPaid($order) : oss()->confirm($order);

    if (in_array($status, ['packed', 'shipped', 'delivered'], true)) {
        oss()->pack($order, $c['owner']);
    }

    if (in_array($status, ['shipped', 'delivered'], true)) {
        oss()->handOver($order, $c['owner']);
    }

    if ($status === 'delivered') {
        oss()->markDelivered($order, $c['admin'], 'Test: customer confirmed.');
    }

    return $order->fresh();
}

function ossDue(array $c): FinancialAccount
{
    return FinancialAccount::gadgetplugDueFor($c['vendor']->id);
}

function ossActAsVendor(User $user, Vendor $vendor): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

function ossActAsAdmin(User $admin): void
{
    test()->actingAs($admin);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

// ── Transitions ─────────────────────────────────────────────────────────────

it('walks a pay-on-delivery order through packed, handed over and delivered, stamping who and when', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');

    expect($order->status)->toBe('delivered')
        ->and($order->packed_by)->toBe($c['owner']->id)
        ->and($order->handed_over_by)->toBe($c['owner']->id)
        ->and($order->delivered_by)->toBe($c['admin']->id)
        ->and($order->delivered_override_reason)->toBe('Test: customer confirmed.')
        ->and($order->packed_at)->not->toBeNull()
        ->and($order->handed_over_at)->not->toBeNull()
        ->and($order->delivered_at)->not->toBeNull();
});

it('refuses a move that is not allowed from where the order is', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');

    expect(fn () => oss()->markDelivered($order, $c['admin'], 'skip ahead'))->toThrow(InvalidOrderTransition::class)
        ->and($order->fresh()->status)->toBe('confirmed');

    $delivered = ossOrder($c, 'pay_on_delivery', 'delivered');
    expect(fn () => oss()->cancel($delivered, $c['admin'], 'too late'))->toThrow(InvalidOrderTransition::class);
});

it('requires a reason to mark delivered or cancel', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    expect(fn () => oss()->markDelivered($order, $c['admin'], '  '))->toThrow(InvalidOrderTransition::class);
    expect(fn () => oss()->cancel(ossOrder($c, 'pay_on_delivery', 'confirmed'), $c['admin'], ''))->toThrow(InvalidOrderTransition::class);
});

// ── Stock ───────────────────────────────────────────────────────────────────

it('takes stock out of the store that held it at handover, with its cost', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'packed', 2, $c['branch']);

    oss()->handOver($order, $c['owner']);

    $branchRow = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->first();
    $mainRow   = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['main']->id)->first();

    expect((int) $branchRow->quantity)->toBe(3)
        ->and((int) $branchRow->reserved)->toBe(0)
        ->and((int) $mainRow->quantity)->toBe(5)
        ->and(InventoryLedger::where('product_id', $c['product']->id)->where('transaction_type', 'dispatched')->where('store_id', $c['branch']->id)->count())->toBe(1)
        ->and(InventoryLedger::where('transaction_type', 'dispatched')->first()->user_id)->toBe($c['owner']->id);
});

it('moves no stock, revenue, payable or ledger when an order is delivered', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    $ledgerRows    = FinancialLedgerEntry::count();
    $inventoryRows = InventoryLedger::count();
    $stock         = ProductStoreStock::where('product_id', $c['product']->id)->pluck('quantity', 'store_id')->all();

    oss()->markDelivered($order, $c['admin'], 'Customer confirmed by phone');

    expect(FinancialLedgerEntry::count())->toBe($ledgerRows)
        ->and(InventoryLedger::count())->toBe($inventoryRows)
        ->and(ProductStoreStock::where('product_id', $c['product']->id)->pluck('quantity', 'store_id')->all())->toBe($stock)
        ->and($order->fresh()->isRevenueRecognized())->toBeFalse();
});

it('releases the hold when GadgetPlug cancels', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');

    oss()->cancel($order, $c['admin'], 'Customer changed their mind');

    $row = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['main']->id)->first();

    expect((int) $row->reserved)->toBe(0)
        ->and((int) $row->quantity)->toBe(5)
        ->and($order->fresh()->cancel_reason)->toBe('Customer changed their mind')
        ->and($order->fresh()->cancelled_by)->toBe($c['admin']->id);
});

// ── Money ───────────────────────────────────────────────────────────────────

it('books a Paystack order\'s revenue into Due from GadgetPlug at paid — items only, not the delivery fee', function () {
    $c = ossVendor();
    FinancialAccount::create(['vendor_id' => $c['vendor']->id, 'name' => 'Bank', 'type' => 'bank']);

    $order = ossOrder($c, 'paystack', 'paid', 2);

    expect($order->isRevenueRecognized())->toBeTrue()
        ->and(ossDue($c)->balance())->toBe(10000.0)
        ->and(FinancialAccount::where('vendor_id', $c['vendor']->id)->where('type', 'bank')->first()->balance())->toBe(0.0);
});

it('splits a Paystack checkout\'s revenue per vendor, each into its own Due account', function () {
    $a = ossVendor();
    $b = ossVendor();

    $orderA = ossOrder($a, 'paystack', 'paid', 1);
    $orderB = ossOrder($b, 'paystack', 'paid', 3);

    expect(ossDue($a)->balance())->toBe(5000.0)
        ->and(ossDue($b)->balance())->toBe(15000.0)
        ->and(ossDue($a)->id)->not->toBe(ossDue($b)->id);
});

it('books pay-on-delivery revenue only when GadgetPlug records the payment, on that vendor order alone', function () {
    $c = ossVendor();
    $other = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered', 2);
    $untouched = ossOrder($other, 'pay_on_delivery', 'delivered', 1);

    expect($order->isRevenueRecognized())->toBeFalse()
        ->and(ossDue($c)->balance())->toBe(0.0);

    $reason = oss()->recordPaymentReceived($order, $c['admin'], 'cash');

    $order->refresh();

    expect($reason)->toBeNull()
        ->and($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('cash')
        ->and($order->payment_collected_by)->toBe($c['admin']->id)
        ->and($order->payment_collected_at)->not->toBeNull()
        ->and(ossDue($c)->balance())->toBe(10000.0)
        ->and(ossDue($other)->balance())->toBe(0.0)
        ->and($untouched->fresh()->isRevenueRecognized())->toBeFalse();
});

it('can record a pay-on-delivery payment once the order is handed over, before delivered', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    oss()->recordPaymentReceived($order, $c['admin'], 'bank_transfer');

    expect($order->fresh()->isRevenueRecognized())->toBeTrue()
        ->and($order->fresh()->status)->toBe('shipped');
});

it('records a payment once — a second call books nothing', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');

    oss()->recordPaymentReceived($order, $c['admin'], 'cash');
    $second = oss()->recordPaymentReceived($order->fresh(), $c['admin'], 'cash');

    expect($second)->toBe('already_recognized')
        ->and(ossDue($c)->balance())->toBe(10000.0)
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->count())->toBe(1);
});

it('refuses to record a payment on a Paystack order, or before handover', function () {
    $c = ossVendor();

    expect(fn () => oss()->recordPaymentReceived(ossOrder($c, 'paystack', 'shipped'), $c['admin'], 'cash'))->toThrow(InvalidOrderTransition::class)
        ->and(fn () => oss()->recordPaymentReceived(ossOrder($c, 'pay_on_delivery', 'confirmed'), $c['admin'], 'cash'))->toThrow(InvalidOrderTransition::class);
});

it('reverses revenue out of Due from GadgetPlug when a paid order is cancelled', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid');

    oss()->cancel($order, $c['admin'], 'Out of stock after all');

    expect(ossDue($c)->balance())->toBe(0.0)
        ->and(FinancialLedgerEntry::where('financial_account_id', ossDue($c)->id)->where('direction', 'out')->count())->toBe(1);
});

it('reverses revenue booked before the switch from the account it went into, not Due', function () {
    $c = ossVendor();
    $bank = FinancialAccount::create(['vendor_id' => $c['vendor']->id, 'name' => 'Bank', 'type' => 'bank']);
    $order = ossOrder($c, 'paystack', 'pending');

    // Revenue as the old rule posted it: straight into the vendor's bank.
    $order->update(['status' => 'paid']);
    FinancialLedger::postEntry($bank, 'in', 10000, $order, 'legacy revenue');
    $order->updateQuietly(['revenue_recognized_at' => now(), 'payment_channel' => 'bank_transfer']);

    oss()->cancel($order->fresh(), $c['admin'], 'Refunded');

    expect($bank->fresh()->balance())->toBe(0.0)
        ->and(FinancialAccount::where('vendor_id', $c['vendor']->id)->where('type', 'gadgetplug_due')->exists())->toBeFalse();
});

// ── The Due account cannot be moved by hand ─────────────────────────────────

it('refuses any posting to Due from GadgetPlug that is not revenue, its reversal, or a payout', function () {
    $c = ossVendor();
    $due = ossDue($c);
    $expense = FinancialAccount::create(['vendor_id' => $c['vendor']->id, 'name' => 'Cash', 'type' => 'cash']);

    expect(fn () => FinancialLedger::postEntry($due, 'out', 100, null, 'spend it'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FinancialLedger::postEntry($due, 'in', 100, null, 'top it up'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FinancialLedger::postEntry($due, 'in', 100, $expense, 'from elsewhere'))->toThrow(InvalidArgumentException::class);
});

it('keeps Due from GadgetPlug out of every "paid from / into" picker', function () {
    $c = ossVendor();
    ossDue($c);
    FinancialAccount::create(['vendor_id' => $c['vendor']->id, 'name' => 'Bank', 'type' => 'bank']);
    FinancialAccount::create(['vendor_id' => $c['vendor']->id, 'name' => 'Cash', 'type' => 'cash']);

    expect(FinancialAccount::where('vendor_id', $c['vendor']->id)->spendable()->pluck('type')->unique()->sort()->values()->all())
        ->toBe(['bank', 'cash']);
});

// ── Supplier payable ────────────────────────────────────────────────────────
// Covered with a real linked line in tests/Feature/VendorLink/SupplierSettlementTest.php.

// ── Who may do what ─────────────────────────────────────────────────────────

it('never lets a vendor mark delivered, cancel, or record a payment', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    expect(fn () => oss()->markDelivered($order, $c['owner'], 'I delivered it'))->toThrow(AuthorizationException::class)
        ->and(fn () => oss()->cancel(ossOrder($c, 'pay_on_delivery', 'confirmed'), $c['owner'], 'no'))->toThrow(AuthorizationException::class)
        ->and(fn () => oss()->recordPaymentReceived($order, $c['owner'], 'cash'))->toThrow(AuthorizationException::class)
        ->and(OrderStatusService::storeOptionsFor($order->fresh()))->toBe([]);
});

it('offers the store only packed and handed to rider', function () {
    $c = ossVendor();

    expect(OrderStatusService::storeOptionsFor(ossOrder($c, 'paystack', 'paid', 1)))->toBe(['packed' => 'Packed', 'shipped' => 'Handed to rider'])
        ->and(OrderStatusService::storeOptionsFor(ossOrder($c, 'paystack', 'packed', 1)))->toBe(['shipped' => 'Handed to rider'])
        ->and(OrderStatusService::storeOptionsFor(ossOrder($c, 'pay_on_delivery', 'delivered', 1)))->toBe([]);
});

it('refuses delivered from the vendor orders list, even when called directly', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    ossActAsVendor($c['owner'], $c['vendor']);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $order->id, 'delivered');

    expect($order->fresh()->status)->toBe('shipped');
});

it('hands over from the vendor orders list through the service', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid');
    ossActAsVendor($c['owner'], $c['vendor']);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $order->id, 'shipped');

    expect($order->fresh()->status)->toBe('shipped')
        ->and($order->fresh()->handed_over_by)->toBe($c['owner']->id)
        ->and(InventoryLedger::where('transaction_type', 'dispatched')->count())->toBe(1);
});

it('lets a storekeeper fulfil their own branch\'s order but not another branch\'s', function () {
    $c = ossVendor();
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    App\Services\VendorRoles::seedFor($c['vendor']);

    $keeper = User::factory()->create();
    $keeper->stores()->attach($c['branch']->id);
    $c['vendor']->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($c['vendor']->id);
    $keeper->assignRole('storekeeper');

    $mine   = ossOrder($c, 'paystack', 'paid', 1, $c['branch']);
    $theirs = ossOrder($c, 'paystack', 'paid', 1, $c['main']);

    setPermissionsTeamId($c['vendor']->id);
    oss()->pack($mine, $keeper);

    expect($mine->fresh()->status)->toBe('packed')
        ->and(fn () => oss()->pack($theirs, $keeper))->toThrow(AuthorizationException::class);
});

it('has no vendor-panel "record payment received" action any more', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');
    ossActAsVendor($c['owner'], $c['vendor']);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionDoesNotExist('recordPaymentReceived');
});

// ── Admin panel ─────────────────────────────────────────────────────────────

it('records a pay-on-delivery payment from the admin order page', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');
    ossActAsAdmin($c['admin']);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('recordPaymentReceived', data: ['payment_channel' => 'cash'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->isRevenueRecognized())->toBeTrue()
        ->and(ossDue($c)->balance())->toBe(10000.0);
});

it('marks delivered from the admin order page with a reason', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    ossActAsAdmin($c['admin']);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('markDelivered', data: ['reason' => 'Rider confirmed by phone', 'notify_customer' => false])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->delivered_override_reason)->toBe('Rider confirmed by phone');
});

it('cancels from the admin order page with a reason', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid');
    ossActAsAdmin($c['admin']);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('cancelOrder', data: ['reason' => 'Customer asked'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and(ossDue($c)->balance())->toBe(0.0);
});

// ── Stale reservations ──────────────────────────────────────────────────────

it('never auto-releases a paid order\'s stock', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid');
    $order->updateQuietly(['reserved_at' => now()->subDays(3)]);

    (new ReleaseStaleReservationsJob)->handle();

    expect($order->fresh()->reservation_released_at)->toBeNull()
        ->and((int) ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['main']->id)->value('reserved'))->toBe(2);
});

it('alerts admins once, instead of releasing, for a confirmed pay-on-delivery order past 24h', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    $order->updateQuietly(['reserved_at' => now()->subHours(30)]);

    (new ReleaseStaleReservationsJob)->handle();
    (new ReleaseStaleReservationsJob)->handle();

    expect($order->fresh()->reservation_released_at)->toBeNull()
        ->and($order->fresh()->stale_alerted_at)->not->toBeNull()
        ->and((int) ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['main']->id)->value('reserved'))->toBe(2)
        ->and($c['admin']->notifications()->count())->toBe(1);
});

it('still releases a hold on an order that was never paid', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'pending');
    $order->updateQuietly(['reserved_at' => now()->subHours(30)]);

    (new ReleaseStaleReservationsJob)->handle();

    expect($order->fresh()->reservation_released_at)->not->toBeNull();
});

// ── Checkmate ───────────────────────────────────────────────────────────────

it('keeps online pay-on-delivery money out of the branch\'s expected cash', function () {
    $c = ossVendor();
    $from = now()->subDay();
    $to = now()->addDay();

    $before = app(StoreReconciliation::class)->forStore($c['main'], $from, $to)['cash'];

    $order = ossOrder($c, 'pay_on_delivery', 'delivered');
    oss()->recordPaymentReceived($order, $c['admin'], 'cash');

    expect(app(StoreReconciliation::class)->forStore($c['main'], $from, $to)['cash'])->toEqual($before);
});

// ── Payouts ─────────────────────────────────────────────────────────────────

it('lets a vendor withdraw only money GadgetPlug has collected', function () {
    $c = ossVendor();
    ossOrder($c, 'paystack', 'paid', 2);                  // collected: 10,000
    ossOrder($c, 'pay_on_delivery', 'delivered', 3);      // delivered, not collected

    expect(VendorPayout::balanceFor($c['vendor']->id))->toBe(['collected' => 10000.0, 'committed' => 0.0, 'available' => 10000.0]);

    VendorPayout::create([
        'vendor_id' => $c['vendor']->id, 'amount' => 4000, 'bank_name' => 'B', 'account_number' => '1',
        'account_name' => 'A', 'status' => 'pending', 'funded_from' => VendorPayout::FUNDED_GADGETPLUG_DUE,
    ]);

    // An old request is drawn on different money and left alone.
    VendorPayout::create([
        'vendor_id' => $c['vendor']->id, 'amount' => 9999, 'bank_name' => 'B', 'account_number' => '1',
        'account_name' => 'A', 'status' => 'pending',
    ]);

    expect(VendorPayout::balanceFor($c['vendor']->id)['available'])->toBe(6000.0);
});

it('moves a paid payout out of Due and into the vendor\'s bank', function () {
    $c = ossVendor();
    // Every vendor starts with a cash and a bank account; the payout lands in the bank one.
    $bank = FinancialAccount::where('vendor_id', $c['vendor']->id)->where('type', 'bank')->orderBy('id')->firstOrFail();
    ossOrder($c, 'paystack', 'paid', 2);

    $payout = VendorPayout::create([
        'vendor_id' => $c['vendor']->id, 'amount' => 4000, 'bank_name' => 'B', 'account_number' => '1',
        'account_name' => 'A', 'status' => 'approved', 'funded_from' => VendorPayout::FUNDED_GADGETPLUG_DUE,
    ]);

    $payout->markPaid('TRX-1', $c['admin']->id);
    $payout->markPaid('TRX-1', $c['admin']->id);

    expect(ossDue($c)->balance())->toBe(6000.0)
        ->and($bank->fresh()->balance())->toBe(4000.0)
        ->and(VendorPayout::balanceFor($c['vendor']->id)['available'])->toBe(6000.0);
});

it('leaves a legacy payout\'s books alone when it is marked paid', function () {
    $c = ossVendor();
    $payout = VendorPayout::create([
        'vendor_id' => $c['vendor']->id, 'amount' => 4000, 'bank_name' => 'B', 'account_number' => '1',
        'account_name' => 'A', 'status' => 'approved',
    ]);

    $payout->markPaid('TRX-OLD');

    expect($payout->fresh()->status)->toBe('paid')
        ->and($payout->fresh()->funded_from)->toBe('legacy')
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

it('settles a new-rule payout from the admin payouts list and shows which rule it was made under', function () {
    $c = ossVendor();
    ossOrder($c, 'paystack', 'paid', 2);

    $payout = VendorPayout::create([
        'vendor_id' => $c['vendor']->id, 'amount' => 4000, 'bank_name' => 'B', 'account_number' => '1',
        'account_name' => 'A', 'status' => 'approved', 'funded_from' => VendorPayout::FUNDED_GADGETPLUG_DUE,
    ]);
    ossActAsAdmin($c['admin']);

    Livewire::test(App\Filament\Resources\VendorPayouts\Pages\ListVendorPayouts::class)
        ->set('activeTab', 'approved')
        ->assertTableColumnFormattedStateSet('funded_from', 'Collected money', $payout)
        ->callTableAction('mark_paid', $payout, data: ['admin_notes' => 'TRX-9'])
        ->assertHasNoTableActionErrors();

    expect($payout->fresh()->status)->toBe('paid')
        ->and(ossDue($c)->balance())->toBe(6000.0);
});
