<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\LogisticsCompanies\LogisticsCompanyResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders as AdminListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder as AdminViewOrder;
use App\Models\Checkout;
use App\Models\DeliveryMessage;
use App\Models\DeliveryRule;
use App\Models\FinancialLedgerEntry;
use App\Models\InventoryLedger;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ProductStoreStock;
use App\Models\StockCostLayer;
use App\Models\User;
use App\Services\Inventory\StockCostLayers;
use App\Services\Orders\DispatchMessages;
use App\Services\Orders\DispatchService;
use App\Services\Orders\InvalidOrderTransition;
use Database\Seeders\DispatcherRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

// Phase 4: platform dispatch. GadgetPlug's dispatch staff assign a platform
// logistics company; the company (and rider) get a signed 48-hour link; the
// customer gets a 4-digit code at handover; the rider marks delivered with
// it; five wrong codes lock the link. Delivered moves nothing financial.

function dispatcherUser(): User
{
    (new DispatcherRoleSeeder)->run();

    $user = User::factory()->create();
    setPermissionsTeamId(null);
    $user->assignRole(DispatcherRoleSeeder::ROLE);

    return $user;
}

function platformCompany(array $over = []): LogisticsCompany
{
    return LogisticsCompany::create($over + [
        'vendor_id' => null, 'name' => 'Swift Riders '.uniqid(), 'phone' => '08031112222', 'whatsapp' => '08031113333', 'is_active' => true,
    ]);
}

/** Put the order in a checkout delivering to Uyo, where the rule expects a ₦1,800 cost and charges ₦2,500. */
function inUyoCheckout(Order $order, float $fee = 2500): Checkout
{
    [$stateId, $lgaId] = checkoutLocation('Uyo');
    DeliveryRule::setForLga(App\Models\Lga::find($lgaId), ['mode' => DeliveryRule::FEE, 'fee' => $fee, 'expected_cost' => 1800]);

    $checkout = Checkout::create([
        'reference' => 'CK-'.uniqid(), 'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone,
        'shipping_address' => $order->shipping_address, 'payment_method' => $order->payment_method,
        'state_id' => $stateId, 'lga_id' => $lgaId,
        'items_total' => $order->total_amount, 'delivery_fee' => $fee, 'grand_total' => (float) $order->total_amount + $fee,
    ]);

    $order->updateQuietly(['checkout_id' => $checkout->id]);

    return $checkout;
}

/** The code the customer was sent, read back from their message. */
function sentCode(Order $order): ?string
{
    $body = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')
        ->where('body', 'like', '%delivery code%')->latest('id')->value('body');

    return $body && preg_match('/delivery code is \*(\d{4})\*/', $body, $m) ? $m[1] : null;
}

function actAsDispatcher(User $user): void
{
    test()->actingAs($user);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

// ── The dispatcher role ─────────────────────────────────────────────────────

it('creates a global dispatcher role holding manage_dispatch', function () {
    $user = dispatcherUser();

    expect($user->hasGlobalPermission('manage_dispatch'))->toBeTrue()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(Dashboard::homeFor($user))->toBe(OrderResource::getUrl('index', panel: 'admin'));
});

it('lets the dispatcher reach Orders and Logistics companies and nothing else in the admin panel', function () {
    actAsDispatcher(dispatcherUser());
    $panel = Filament::getPanel('admin');
    $allowed = [OrderResource::class, LogisticsCompanyResource::class];

    foreach ($panel->getResources() as $resource) {
        $expected = in_array($resource, $allowed, true);

        expect($resource::canAccess())->toBe($expected, "{$resource}::canAccess() should be ".($expected ? 'true' : 'false'));

        if (! $expected && array_key_exists('index', $resource::getPages())) {
            test()->getJson($resource::getUrl('index', panel: 'admin'))->assertForbidden();
        }
    }

    foreach ($panel->getPages() as $page) {
        if ($page === Dashboard::class) {
            expect($page::shouldRegisterNavigation())->toBeFalse();

            continue;
        }

        expect($page::canAccess())->toBeFalse("{$page}::canAccess() should be false for the dispatcher");
    }

    test()->get(OrderResource::getUrl('index', panel: 'admin'))->assertOk();
    test()->get(LogisticsCompanyResource::getUrl('index', panel: 'admin'))->assertOk();
});

it('runs the order actions as a dispatcher, not only as super_admin', function () {
    $c = ossVendor();
    $dispatcher = dispatcherUser();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    oss()->recordPaymentReceived($order, $dispatcher, 'cash');
    oss()->markDelivered($order->fresh(), $dispatcher, 'Customer confirmed by phone');
    oss()->cancel(ossOrder($c, 'pay_on_delivery', 'confirmed', 1), $dispatcher, 'Customer cancelled');

    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->isRevenueRecognized())->toBeTrue();
});

it('refuses dispatch actions to anyone without manage_dispatch', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    $company = platformCompany();

    expect(fn () => app(DispatchService::class)->assign($order, $c['owner'], $company->id))->toThrow(AuthorizationException::class)
        ->and(fn () => oss()->returnToStore($order, $c['owner'], 'no'))->toThrow(AuthorizationException::class);

    test()->actingAs($c['owner']);
    expect(OrderResource::canAccess())->toBeFalse()
        ->and(LogisticsCompanyResource::canAccess())->toBeFalse();
});

// ── Assignment, cost, paid to company ───────────────────────────────────────

it('pre-fills the delivery cost from the area\'s expected cost and sends the link to the company and rider', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'packed');
    inUyoCheckout($order);
    $company = platformCompany();
    actAsDispatcher($dispatcher = dispatcherUser());

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('assignCompany')
        ->assertActionDataSet(['company_cost' => 1800.0])
        ->setActionData(['logistics_company_id' => $company->id, 'rider_name' => 'Emeka', 'rider_phone' => '08094445555', 'notify' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $order->refresh();
    $links = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'rider')->get();

    expect($order->logistics_company_id)->toBe($company->id)
        ->and((float) $order->company_cost)->toBe(1800.0)
        ->and($order->rider_name)->toBe('Emeka')
        ->and($order->dispatch_assigned_by)->toBe($dispatcher->id)
        ->and($links)->toHaveCount(2)
        ->and($links->pluck('to_number')->all())->toContain('2348031113333', '2348094445555')
        ->and($links->first()->body)->toContain('/dispatch/'.$order->id.'/')
        ->and($links->first()->body)->not->toMatch('/delivery code is/');
});

it('names items for the rider by shelf name with the online name in brackets', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'packed');
    $order->items()->update(['product_name' => 'ITEL 2160 OLD ONE', 'product_display_name' => 'itel 2160 Dual SIM Keypad Phone']);

    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id);

    expect(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'rider')->value('body'))
        ->toContain('ITEL 2160 OLD ONE (itel 2160 Dual SIM Keypad Phone)');
});

it('never assigns a vendor\'s own company or an inactive one', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'packed');
    $vendorOwn = LogisticsCompany::create(['vendor_id' => $c['vendor']->id, 'name' => 'Own', 'phone' => '0801']);
    $inactive = platformCompany(['is_active' => false]);

    expect(fn () => app(DispatchService::class)->assign($order, $c['admin'], $vendorOwn->id))->toThrow(InvalidOrderTransition::class)
        ->and(fn () => app(DispatchService::class)->assign($order, $c['admin'], $inactive->id))->toThrow(InvalidOrderTransition::class);
});

it('marks the company paid once, and locks the cost after that', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'packed');
    $company = platformCompany();
    $service = app(DispatchService::class);

    $service->assign($order, $c['admin'], $company->id, cost: 1500, notify: false);
    $service->markCompanyPaid($order->fresh(), $c['admin']);
    $paidAt = $order->fresh()->company_paid_at;

    $service->assign($order->fresh(), $c['admin'], $company->id, cost: 9999, notify: false);
    $service->markCompanyPaid($order->fresh(), $c['admin']);

    expect((float) $order->fresh()->company_cost)->toBe(1500.0)
        ->and($order->fresh()->company_paid_at->equalTo($paidAt))->toBeTrue()
        ->and($order->fresh()->company_paid_by)->toBe($c['admin']->id)
        // No ledger yet: recorded, not posted.
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('direction', 'out')->exists())->toBeFalse();
});

it('shows the fee carried by each part against its cost on the dispatch board', function () {
    $c = ossVendor();
    $first = ossOrder($c, 'paystack', 'packed', 1);
    $checkout = inUyoCheckout($first, fee: 2500);
    $second = ossOrder($c, 'paystack', 'packed', 1);
    $second->updateQuietly(['checkout_id' => $checkout->id]);

    $company = platformCompany();
    app(DispatchService::class)->assign($first, $c['admin'], $company->id, cost: 1800, notify: false);
    app(DispatchService::class)->assign($second, $c['admin'], $company->id, cost: 1000, notify: false);

    expect($first->fresh()->deliveryFeeShare())->toBe(2500.0)
        ->and($second->fresh()->deliveryFeeShare())->toBe(0.0);

    actAsDispatcher(dispatcherUser());

    Livewire::test(AdminListOrders::class)
        ->assertTableColumnFormattedStateSet('fee_vs_cost', 'Fee ₦2,500 · Cost ₦1,800 · +₦700', $first)
        ->assertTableColumnFormattedStateSet('fee_vs_cost', 'Fee ₦0 · Cost ₦1,000 · −₦1,000', $second)
        ->assertSee('Whole checkout: fee ₦2,500 vs cost ₦2,800');
});

// ── The delivery code ───────────────────────────────────────────────────────

it('creates a hashed code at handover and sends it to the customer only', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    $code = sentCode($order);

    expect($code)->toMatch('/^\d{4}$/')
        ->and($order->delivery_code_hash)->not->toBe($code)
        ->and(Hash::check($code, $order->delivery_code_hash))->toBeTrue()
        ->and(DeliveryMessage::where('order_id', $order->id)->where('body', 'like', "%{$code}%")->where('recipient_type', '!=', 'customer')->exists())->toBeFalse();
});

it('never shows the code on the rider page or on /track', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, notify: false);
    // A known code, checked against the visible text, so a random one cannot
    // collide with a date, an amount or a token by chance.
    $code = '7391';
    $order->updateQuietly(DispatchService::codeColumns($code));

    $rider = strip_tags($this->get(DispatchMessages::link($order->fresh()))->assertOk()->getContent());
    $track = strip_tags($this->get(route('track-order', ['ref' => $order->reference]))->assertOk()->getContent());

    expect($rider)->toContain($order->reference)->not->toContain($code)
        ->and($track)->not->toContain($code);
});

// ── The rider's page ────────────────────────────────────────────────────────

it('refuses a tampered, expired or other order\'s link', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    $other = ossOrder($c, 'pay_on_delivery', 'shipped', 1);
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, notify: false);
    $link = DispatchMessages::link($order->fresh());

    $this->get($link)->assertOk();
    $this->getJson($link.'x')->assertForbidden();
    $this->getJson(str_replace('/dispatch/'.$order->id.'/', '/dispatch/'.$other->id.'/', $link))->assertForbidden();

    $this->travel(49)->hours();
    $this->getJson($link)->assertForbidden();
});

it('shows a rider with a dead link a plain explanation, not a login page', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, notify: false);
    $link = DispatchMessages::link($order->fresh());

    $this->travel(49)->hours();

    $this->get($link)
        ->assertForbidden()
        ->assertSee('This delivery link has expired or is no longer valid');
});

it('stops an old link working once the order is reassigned', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    $service = app(DispatchService::class);
    $service->assign($order, $c['admin'], platformCompany()->id, notify: false);
    $old = DispatchMessages::link($order->fresh());
    $code = sentCode($order);

    $service->assign($order->fresh(), $c['admin'], platformCompany()->id, notify: false);

    $this->get($old)->assertOk()->assertSee('This link has been replaced');
    $this->post($old, ['code' => $code])->assertOk();

    expect($order->fresh()->status)->toBe('shipped');
});

it('marks delivered with the right code, and nothing financial or in stock moves', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, notify: false);
    $link = DispatchMessages::link($order->fresh());

    $ledger = FinancialLedgerEntry::count();
    $inventory = InventoryLedger::count();
    $stock = ProductStoreStock::where('product_id', $c['product']->id)->pluck('quantity', 'store_id')->all();

    $this->post($link, ['code' => sentCode($order)])->assertOk()->assertSee('Delivered');

    $order->refresh();

    expect($order->status)->toBe('delivered')
        ->and($order->delivered_via)->toBe('code')
        ->and($order->delivered_by)->toBeNull()
        ->and($order->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe($ledger)
        ->and(InventoryLedger::count())->toBe($inventory)
        ->and(ProductStoreStock::where('product_id', $c['product']->id)->pluck('quantity', 'store_id')->all())->toBe($stock);
});

it('locks after five wrong codes, alerts dispatch, and refuses even the right code until a new one is sent', function () {
    $c = ossVendor();
    $dispatcher = dispatcherUser();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, notify: false);
    $link = DispatchMessages::link($order->fresh());
    $code = sentCode($order);
    $wrong = $code === '0000' ? '1111' : '0000';

    for ($i = 1; $i <= 4; $i++) {
        $this->post($link, ['code' => $wrong])->assertOk()->assertSee('That code is not right');
    }

    expect($order->fresh()->isDeliveryCodeLocked())->toBeFalse();

    $this->post($link, ['code' => $wrong])->assertSee('This delivery is locked', false);

    expect($order->fresh()->isDeliveryCodeLocked())->toBeTrue()
        ->and($order->fresh()->delivery_code_attempts)->toBe(5)
        ->and($dispatcher->notifications()->count())->toBe(1)
        ->and($c['admin']->notifications()->count())->toBe(1);

    $this->post($link, ['code' => $code]);
    expect($order->fresh()->status)->toBe('shipped');

    // Dispatch sends a new code: unlocked, attempts reset, the old code dead.
    app(DispatchService::class)->issueNewCode($order->fresh(), $dispatcher);
    $newCode = sentCode($order);

    expect($order->fresh()->isDeliveryCodeLocked())->toBeFalse()
        ->and($order->fresh()->delivery_code_attempts)->toBe(0);

    if ($newCode !== $code) {
        $this->post($link, ['code' => $code])->assertSee('That code is not right');
    }

    $this->post($link, ['code' => $newCode])->assertSee('Delivered');
    expect($order->fresh()->status)->toBe('delivered');
});

it('logs an override with its reason', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    oss()->markDelivered($order, $c['admin'], 'Rider\'s phone died; customer confirmed by call');

    expect($order->fresh()->delivered_via)->toBe('override')
        ->and(Spatie\Activitylog\Models\Activity::where('subject_id', $order->id)
            ->where('subject_type', $order->getMorphClass())
            ->get()
            ->contains(fn ($a) => data_get($a->properties, 'attributes.delivered_override_reason') === 'Rider\'s phone died; customer confirmed by call'))->toBeTrue();
});

// ── Failed delivery ─────────────────────────────────────────────────────────

it('puts a failed delivery back on the shelf of the branch it left, at the cost it left with', function () {
    $c = ossVendor();
    StockCostLayers::receive($c['product']->id, $c['branch']->id, 5, 3200.0);
    $order = ossOrder($c, 'paystack', 'shipped', 2, $c['branch']);
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, notify: false);
    $link = DispatchMessages::link($order->fresh());

    $dispatchedCost = (float) InventoryLedger::where('transaction_type', 'dispatched')->where('reference', $order->reference)->sum('cost_total');
    $revenueEntries = FinancialLedgerEntry::count();

    oss()->returnToStore($order->fresh(), $c['admin'], 'Customer unreachable after three calls');

    $branch = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->first();
    $back = InventoryLedger::where('transaction_type', 'online_return')->sole();
    $restored = StockCostLayer::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->latest('id')->first();

    expect($order->fresh()->status)->toBe('returned')
        ->and($order->fresh()->return_reason)->toBe('Customer unreachable after three calls')
        ->and((int) $branch->quantity)->toBe(5)
        ->and($back->store_id)->toBe($c['branch']->id)
        ->and((int) $back->quantity_change)->toBe(2)
        ->and((float) $restored->unit_cost)->toBe(round($dispatchedCost / 2, 2))
        ->and(FinancialLedgerEntry::count())->toBe($revenueEntries);

    // The rider's link is dead.
    $this->get($link)->assertSee('This link has been replaced');

    // Cancelling settles the money and moves no stock a second time.
    oss()->cancel($order->fresh(), $c['admin'], 'Not delivering again');

    expect(ossDue($c)->balance())->toBe(0.0)
        ->and((int) ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->value('quantity'))->toBe(5)
        ->and(InventoryLedger::where('transaction_type', 'reservation_released')->where('reference', $order->reference)->exists())->toBeFalse();
});

it('offers the failed-delivery action only while the order is with the rider', function () {
    $c = ossVendor();
    actAsDispatcher(dispatcherUser());

    Livewire::test(AdminViewOrder::class, ['record' => ossOrder($c, 'paystack', 'packed', 1)->getRouteKey()])
        ->assertActionHidden('returnToStore');

    Livewire::test(AdminViewOrder::class, ['record' => ossOrder($c, 'paystack', 'shipped', 1)->getRouteKey()])
        ->assertActionVisible('returnToStore');
});

// ── Logistics companies (admin) ─────────────────────────────────────────────

it('lists and creates only platform logistics companies', function () {
    $c = ossVendor();
    $vendorOwn = LogisticsCompany::create(['vendor_id' => $c['vendor']->id, 'name' => 'Vendor Own Riders', 'phone' => '0801']);
    $platform = platformCompany(['name' => 'GadgetPlug Partner']);
    actAsDispatcher(dispatcherUser());

    Livewire::test(App\Filament\Resources\LogisticsCompanies\Pages\ManageLogisticsCompanies::class)
        ->assertCanSeeTableRecords([$platform])
        ->assertCanNotSeeTableRecords([$vendorOwn])
        ->callAction('create', data: ['name' => 'New Partner', 'phone' => '08055556666', 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(LogisticsCompany::where('name', 'New Partner')->sole()->vendor_id)->toBeNull();
});

it('shows dispatch staff the cost, payment and code state on the order — never the code', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    app(DispatchService::class)->assign($order, $c['admin'], platformCompany()->id, cost: 1750, notify: false);
    $code = '7391';
    $order->updateQuietly(DispatchService::codeColumns($code));
    actAsDispatcher(dispatcherUser());

    $html = Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('We pay the company')
        ->assertSee('₦1,750')
        ->assertSee('Sent to customer')
        ->html();

    expect(strip_tags($html))->not->toContain($code);
});

// ── The stage tabs ──────────────────────────────────────────────────────────

it('opens every dispatch stage tab, each showing only its own orders', function () {
    // A vendor each: one fixture vendor's stock runs out after a couple of orders.
    $confirmed = ossOrder(ossVendor(), 'paystack', 'confirmed');
    $packed = ossOrder(ossVendor(), 'paystack', 'packed');
    $shipped = ossOrder(ossVendor(), 'pay_on_delivery', 'shipped');

    actAsDispatcher(dispatcherUser());

    // Every tab but "All" used to crash: its closure named the query $q, so
    // Filament gave it an empty, model-less builder.
    foreach (['needs_packing', 'packed', 'with_rider', 'delivered', 'payment_pending', 'returned', 'cancelled'] as $tab) {
        Livewire::test(AdminListOrders::class)->set('activeTab', $tab)->assertOk();
    }

    Livewire::test(AdminListOrders::class)
        ->set('activeTab', 'needs_packing')
        ->assertCanSeeTableRecords([$confirmed])
        ->assertCanNotSeeTableRecords([$packed, $shipped])
        ->set('activeTab', 'with_rider')
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$confirmed, $packed]);
});
