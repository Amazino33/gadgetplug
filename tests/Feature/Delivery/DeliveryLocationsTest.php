<?php

use App\Filament\Pages\DeliverySettings;
use App\Models\Checkout;
use App\Models\DeliveryMessage;
use App\Models\DeliveryRule;
use App\Models\FinancialLedgerEntry;
use App\Models\Lga;
use App\Models\Order;
use App\Models\State;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Models\WalletTransaction;
use App\Services\Affiliate\ResellerCheckoutService;
use App\Services\Delivery\DeliveryDistance;
use App\Services\Delivery\DeliveryPricing;
use App\Services\Delivery\DistanceBandPricer;
use Database\Factories\ProductFactory;
use Database\Seeders\CatalogueEditorRoleSeeder;
use Database\Seeders\DeliveryRulesSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\NigeriaLocationsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Delivery locations & fees: every state and LGA, a rule per state with LGA
// overrides, straight-line distance from store hubs, and checkout charging the
// right fee — once, on the checkout, never inside any vendor's order.

function lga(string $name, string $state = 'Akwa Ibom'): Lga
{
    return Lga::where('state_id', State::where('name', $state)->value('id'))->where('name', $name)->firstOrFail();
}

function state(string $name): State
{
    return State::where('name', $name)->firstOrFail();
}

function deliveryVendor(string $name = 'Delivery Vendor'): Vendor
{
    return Vendor::factory()->onlineSalesEnabled()->create(['name' => $name.' '.uniqid()]);
}

function deliveryProduct(Vendor $vendor, float $price = 10000)
{
    return ProductFactory::new()->withPhoto()->create([
        'vendor_id' => $vendor->id, 'store_id' => $vendor->defaultStore->id,
        'price' => $price, 'cost_price' => $price * 0.6, 'stock_quantity' => 10,
    ]);
}

/** A checkout form filled in for a given LGA. */
function deliveryCheckout(array $lines, Lga $lga, string $method = 'pay_on_delivery')
{
    Session::forget('payment_success');
    Session::put('cart', collect($lines)->mapWithKeys(fn ($l) => [$l[0]->id => ['quantity' => $l[1], 'max' => 10]])->all());

    return Volt::test('checkout')
        ->set('name', 'Ada Delivery')
        ->set('email', 'ada@example.com')
        ->set('phone', '08057189760')
        ->set('stateId', $lga->state_id)
        ->set('lgaId', $lga->id)
        ->set('address', '12 Test Close, enough characters')
        ->set('paymentMethod', $method);
}

describe('the locations data', function () {
    test('exactly 37 states and 774 LGAs, and re-seeding changes nothing', function () {
        expect(State::count())->toBe(37)->and(Lga::count())->toBe(774);

        $ids = Lga::orderBy('id')->pluck('id')->all();
        (new NigeriaLocationsSeeder)->run();
        (new DeliveryRulesSeeder)->run();

        expect(State::count())->toBe(37)
            ->and(Lga::count())->toBe(774)
            ->and(Lga::orderBy('id')->pluck('id')->all())->toBe($ids)
            ->and(DeliveryRule::whereNull('lga_id')->count())->toBe(37);
    });

    test('day one: Uyo, Mkpat Enin and Eket are free; everywhere else is not delivered', function () {
        $pricing = app(DeliveryPricing::class);

        foreach (['Uyo', 'Mkpat Enin', 'Eket'] as $name) {
            expect($pricing->quote(lga($name))->mode)->toBe('free');
        }

        expect($pricing->quote(lga('Ikot Ekpene'))->deliverable())->toBeFalse()
            ->and($pricing->quote(lga('Ikeja', 'Lagos'))->deliverable())->toBeFalse();
    });

    test('the rules seeder never overwrites a rule the owner changed', function () {
        DeliveryRule::setForLga(lga('Uyo'), ['mode' => 'fee', 'fee' => 700]);

        (new DeliveryRulesSeeder)->run();

        expect(app(DeliveryPricing::class)->quote(lga('Uyo'))->fee)->toBe(700.0);
    });
});

describe('which rule applies', function () {
    test('an LGA override beats its state default; otherwise the state default applies', function () {
        DeliveryRule::setForState(state('Akwa Ibom')->id, ['mode' => 'fee', 'fee' => 2000, 'delivery_days' => '1–2 days']);
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500, 'expected_cost' => 900]);

        $pricing = app(DeliveryPricing::class);

        $override = $pricing->quote(lga('Ikot Ekpene'));
        $default = $pricing->quote(lga('Abak'));
        $free = $pricing->quote(lga('Uyo'));

        expect([$override->mode, $override->fee, $override->source, $override->expectedCost])->toBe(['fee', 1500.0, 'lga', 900.0])
            ->and([$default->mode, $default->fee, $default->source, $default->deliveryDays])->toBe(['fee', 2000.0, 'state', '1–2 days'])
            ->and([$free->mode, $free->fee, $free->source])->toBe(['free', 0.0, 'lga']);
    });

    test('a fee rule needs a fee above zero', function () {
        expect(fn () => DeliveryRule::setForLga(lga('Abak'), ['mode' => 'fee', 'fee' => 0]))
            ->toThrow(InvalidArgumentException::class);
    });

    test('every rule change is activity-logged with who made it and old → new', function () {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1800]);

        $log = Activity::where('log_name', 'delivery')->where('event', 'updated')->latest('id')->first();

        expect($log->causer_id)->toBe($admin->id)
            ->and((float) $log->properties['old']['fee'])->toBe(1500.0)
            ->and((float) $log->properties['attributes']['fee'])->toBe(1800.0);
    });
});

describe('distance from the nearest hub (straight-line)', function () {
    test('about 0 km for a hub\'s own LGA, and Uyo → Eket about 43 km', function () {
        $uyo = lga('Uyo');
        Store::first()?->delete();
        $vendor = deliveryVendor();
        $vendor->defaultStore->update(['latitude' => $uyo->latitude, 'longitude' => $uyo->longitude]);

        $distance = app(DeliveryDistance::class);

        expect($distance->kmFromNearestHub($uyo))->toBe(0.0)
            ->and($distance->kmFromNearestHub(lga('Eket')))->toBeGreaterThan(40.0)->toBeLessThan(46.0);
    });

    test('no hub pinned means no distance, never a made-up one', function () {
        Store::query()->update(['latitude' => null, 'longitude' => null]);

        expect(app(DeliveryDistance::class)->kmFromNearestHub(lga('Uyo')))->toBeNull();
    });
});

describe('checkout', function () {
    test('charges the fee once, on the checkout — never inside a vendor order — and snapshots the rule', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500, 'expected_cost' => 900, 'delivery_days' => 'Same day']);
        $product = deliveryProduct(deliveryVendor(), 10000);

        $form = deliveryCheckout([[$product, 2]], lga('Ikot Ekpene'));

        expect($form->get('deliveryFee'))->toBe(1500.0); // shown live

        $form->call('processCheckout');

        $checkout = Checkout::sole();
        $order = Order::sole();

        expect((float) $checkout->items_total)->toBe(20000.0)
            ->and((float) $checkout->delivery_fee)->toBe(1500.0)
            ->and((float) $checkout->grand_total)->toBe(21500.0)
            ->and((float) $order->total_amount)->toBe(20000.0)
            ->and($checkout->lga_id)->toBe(lga('Ikot Ekpene')->id)
            ->and($checkout->shipping_address)->toStartWith('Ikot Ekpene, Akwa Ibom State — ')
            ->and($checkout->delivery_rule_snapshot)->toMatchArray([
                'mode' => 'fee', 'fee' => 1500.0, 'expected_cost' => 900.0, 'delivery_days' => 'Same day',
                'source' => 'lga', 'lga' => 'Ikot Ekpene', 'state' => 'Akwa Ibom', 'distance_kind' => 'straight_line',
            ]);
    });

    test('a fee changed after the order never changes what the order was charged', function () {
        $rule = DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Ikot Ekpene'))->call('processCheckout');

        $rule->update(['fee' => 4000]);

        $checkout = Checkout::sole()->fresh();
        expect((float) $checkout->delivery_fee)->toBe(1500.0)
            ->and($checkout->delivery_rule_snapshot['fee'])->toEqual(1500) // JSON keeps 1500, not 1500.0
            ->and($checkout->delivery_rule_snapshot['rule_id'])->toBe($rule->id);
    });

    test('a tampered fee in the request is ignored; the server works it out again', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);

        deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Ikot Ekpene'))
            ->set('deliveryFee', 1)
            ->set('deliveryMode', 'free')
            ->call('processCheckout');

        expect((float) Checkout::sole()->delivery_fee)->toBe(1500.0);
    });

    test('a not-delivered LGA can be chosen, says so, and blocks the order', function () {
        config(['app.support_whatsapp' => '08011112222']);
        $form = deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Ikeja', 'Lagos'));

        // The address form is step 2 of the checkout.
        $form->call('choosePayment', 'pay_on_delivery')->assertSee("We don't deliver to Ikeja yet.", false)->assertSee('Ask us on WhatsApp', false);

        $form->call('processCheckout')->assertHasErrors(['lgaId']);

        expect(Checkout::count())->toBe(0)->and(Order::count())->toBe(0);
    });

    test('an LGA from another state than the one selected is refused', function () {
        deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Uyo'))
            ->set('stateId', state('Lagos')->id)
            ->set('lgaId', lga('Uyo')->id)
            ->call('processCheckout')
            ->assertHasErrors(['lgaId']);

        expect(Checkout::count())->toBe(0);
    });

    test('Paystack is asked for items + fee, once, on the checkout', function () {
        config(['services.paystack.secret_key' => 'sk_test_delivery']);
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);

        deliveryCheckout([[deliveryProduct(deliveryVendor(), 10000), 1]], lga('Ikot Ekpene'), 'paystack')->call('processCheckout');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'transaction/initialize') && $r['amount'] === 1150000);
    });

    test('Paystack revenue books each vendor\'s subtotal only — the fee is not vendor revenue', function () {
        config(['services.paystack.secret_key' => 'sk_test_delivery']);
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        $vendor = deliveryVendor();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
        deliveryCheckout([[deliveryProduct($vendor, 10000), 1]], lga('Ikot Ekpene'), 'paystack')->call('processCheckout');
        $checkout = Checkout::sole();

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'amount' => 1150000, 'currency' => 'NGN', 'reference' => $checkout->reference,
        ]])]);
        $this->get(route('payment.callback', ['reference' => $checkout->reference]));

        expect(Order::sole()->status)->toBe('paid')
            ->and((float) FinancialLedgerEntry::where('direction', 'in')->sum('amount'))->toBe(10000.0);
    });

    test('pay on delivery: the confirmation shows the fee as its own line and the full amount to collect', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        deliveryCheckout([[deliveryProduct(deliveryVendor(), 10000), 1]], lga('Ikot Ekpene'))->call('processCheckout');
        $checkout = Checkout::sole();

        Session::put('payment_success', $checkout->reference);
        $screen = Volt::test('checkout');

        expect($screen->get('paidTotal'))->toBe(11500.0)
            ->and($screen->get('paidDeliveryFee'))->toBe(1500.0);

        $screen->assertSee('Total (Pay on Delivery)')->assertSee('₦1,500')->assertSee('₦11,500');
    });
});

describe('the customer confirmation shows the delivery fee', function () {
    beforeEach(fn () => config(['services.messaging.whatsapp_driver' => 'log_null']));

    function confirmationFor(Order $order): string
    {
        return DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')->sole()->body;
    }

    test('pay on delivery: items, delivery fee and the total to pay', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        $vendor = deliveryVendor();
        MessageTemplateSeeder::forVendor($vendor);

        deliveryCheckout([[deliveryProduct($vendor, 10000), 2]], lga('Ikot Ekpene'))->call('processCheckout');

        expect(confirmationFor(Order::sole()))
            ->toContain('*Items:* ₦20,000.00')
            ->toContain('*Delivery:* ₦1,500.00')
            ->toContain('*Total to pay:* ₦21,500.00')
            ->not->toContain('*Total:*');
    });

    test('Paystack: the same lines, as total paid', function () {
        config(['services.paystack.secret_key' => 'sk_test_delivery']);
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        $vendor = deliveryVendor();
        MessageTemplateSeeder::forVendor($vendor);
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
        deliveryCheckout([[deliveryProduct($vendor, 10000), 1]], lga('Ikot Ekpene'), 'paystack')->call('processCheckout');
        $checkout = Checkout::sole();

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'amount' => 1150000, 'currency' => 'NGN', 'reference' => $checkout->reference,
        ]])]);
        $this->get(route('payment.callback', ['reference' => $checkout->reference]));

        expect(confirmationFor(Order::sole()))
            ->toContain('*Items:* ₦10,000.00')
            ->toContain('*Delivery:* ₦1,500.00')
            ->toContain('*Total paid:* ₦11,500.00');
    });

    test('a free delivery says so', function () {
        $vendor = deliveryVendor();
        MessageTemplateSeeder::forVendor($vendor);

        deliveryCheckout([[deliveryProduct($vendor, 10000), 1]], lga('Uyo'))->call('processCheckout');

        expect(confirmationFor(Order::sole()))->toContain('*Delivery:* Free')->toContain('*Total to pay:* ₦10,000.00');
    });

    // One confirmation per checkout (Prompt 3, Phase 5): a checkout split
    // across vendors confirms once, listing every part, with the fee and the
    // grand total once.
    test('mixed checkout: one confirmation for the whole checkout, every part listed, fee and total once', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);
        $a = deliveryVendor('Alpha');
        $b = deliveryVendor('Beta');
        foreach ([$a, $b] as $v) {
            MessageTemplateSeeder::forVendor($v);
        }
        $pa = deliveryProduct($a, 10000);
        $pb = deliveryProduct($b, 40000);
        $pa->update(['name' => 'ALPHA THING']);
        $pb->update(['name' => 'BETA THING']);

        deliveryCheckout([[$pa, 1], [$pb, 1]], lga('Ikot Ekpene'))->call('processCheckout');

        $checkout = Checkout::sole();
        [$first, $second] = $checkout->orders->all();
        $confirmations = DeliveryMessage::whereIn('order_id', [$first->id, $second->id])
            ->where('recipient_type', 'customer')->get();
        $body = $confirmations->sole()->body;

        expect($confirmations)->toHaveCount(1)
            ->and($confirmations->sole()->vendor_id)->toBeNull()
            ->and($body)->toContain($checkout->reference)
            ->toContain('ALPHA THING')->toContain('BETA THING')
            ->toContain('*'.$first->reference.'*')->toContain('*'.$second->reference.'*')
            ->toContain('*Items:* ₦50,000.00')
            ->toContain('*Delivery:* ₦1,500.00')
            ->toContain('*Total to pay:* ₦51,500.00')
            ->and(substr_count($body, '₦1,500.00'))->toBe(1);
    });

    test('vendors\' stored copies of the template are switched to the new lines; customised ones are left alone', function () {
        $vendor = deliveryVendor();
        $old = "Hello\n\n*Your items*\n{{order_items}}\n\n*Total:* {{total}}\n*Payment:* {{payment_method}}";
        $stored = App\Models\MessageTemplate::create(['vendor_id' => $vendor->id, 'key' => 'customer_received', 'recipient_type' => 'customer', 'channel' => 'whatsapp', 'body' => $old]);
        $custom = App\Models\MessageTemplate::create(['vendor_id' => deliveryVendor()->id, 'key' => 'customer_received', 'recipient_type' => 'customer', 'channel' => 'whatsapp', 'body' => 'Custom {{order_items}}']);

        (require database_path('migrations/2026_10_21_120000_add_payment_summary_to_customer_received_template.php'))->up();

        expect($stored->fresh()->body)->toContain('{{payment_summary}}')->not->toContain('*Total:* {{total}}')
            ->and($custom->fresh()->body)->toBe('Custom {{order_items}}');
    });
});

describe('reseller wallet purchases are atomic', function () {
    test('when a later vendor\'s stock fails, no checkout, order or wallet debit survives', function () {
        $a = deliveryVendor('Alpha');
        $b = deliveryVendor('Beta');
        $ok = deliveryProduct($a, 1000);
        $short = ProductFactory::new()->withPhoto()->create(['vendor_id' => $b->id, 'store_id' => $b->defaultStore->id, 'price' => 1000, 'stock_quantity' => 0]);

        $user = User::factory()->create(['phone' => '08040000000']);
        $affiliate = App\Models\Affiliate::findOrCreateForUser($user);
        WalletTransaction::create(['affiliate_id' => $affiliate->id, 'type' => 'credit', 'amount' => 50000, 'description' => 'Top-up']);

        expect(fn () => app(ResellerCheckoutService::class)->purchase(
            $affiliate,
            [['product' => $ok, 'quantity' => 1], ['product' => $short, 'quantity' => 1]],
            'Uyo, Akwa Ibom State — Address',
        ))->toThrow(Exception::class);

        expect(Checkout::count())->toBe(0)
            ->and(Order::count())->toBe(0)
            ->and(WalletTransaction::where('type', 'debit')->count())->toBe(0)
            ->and(App\Models\ProductStoreStock::where('product_id', $ok->id)->value('reserved'))->toBe(0);
    });
});

describe('price by distance', function () {
    beforeEach(function () {
        $uyo = lga('Uyo');
        deliveryVendor()->defaultStore->update(['latitude' => $uyo->latitude, 'longitude' => $uyo->longitude]);
    });

    test('the preview changes nothing until applied; applying writes ordinary LGA overrides', function () {
        $bands = [
            ['from' => 0, 'to' => 15, 'mode' => 'free'],
            ['from' => 15, 'to' => 60, 'mode' => 'fee', 'fee' => 1500],
            ['from' => 60, 'to' => null, 'mode' => 'not_delivered'],
        ];
        $before = DeliveryRule::orderBy('id')->get()->toArray();

        $preview = app(DistanceBandPricer::class)->preview($bands, state('Akwa Ibom')->id);

        expect($preview)->not->toBeEmpty()
            ->and(DeliveryRule::orderBy('id')->get()->toArray())->toBe($before)
            ->and($preview->firstWhere('lga', 'Eket'))->toMatchArray(['to_mode' => 'fee', 'to_fee' => 1500.0]);

        $changed = app(DistanceBandPricer::class)->apply($bands, state('Akwa Ibom')->id);

        expect($changed)->toBe($preview->count())
            ->and(app(DeliveryPricing::class)->quote(lga('Eket')))->toMatchObject(['mode' => 'fee', 'fee' => 1500.0, 'source' => 'lga'])
            // Outside the chosen state nothing moved.
            ->and(app(DeliveryPricing::class)->quote(lga('Ikeja', 'Lagos'))->source)->toBe('state');
    });

    test('without a pinned store the helper refuses rather than guessing', function () {
        Store::query()->update(['latitude' => null, 'longitude' => null]);

        expect(fn () => app(DistanceBandPricer::class)->preview([['from' => 0, 'to' => 10, 'mode' => 'free']]))
            ->toThrow(InvalidArgumentException::class, 'Pin at least one store');
    });
});

describe('the Delivery settings page', function () {
    test('super admin can open it; it shows the map, the warning-free hubs and the rules', function () {
        setPermissionsTeamId(null);
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin)->get(DeliverySettings::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee('Delivery settings')
            ->assertSee('leaflet@1.9.4', false)
            ->assertSee('straight-line');
    });

    test('someone with manage_delivery_settings gets in, lands there, and can change a rule — logged', function () {
        setPermissionsTeamId(null);
        $staff = User::factory()->create();
        $staff->givePermissionTo('manage_delivery_settings');

        $this->actingAs($staff)->get('/admin')->assertRedirect(DeliverySettings::getUrl(panel: 'admin'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(DeliverySettings::class)
            ->call('saveLgaRule', lga('Ikot Ekpene')->id, ['mode' => 'fee', 'fee' => 1500, 'expected_cost' => 900, 'delivery_days' => 'Same day'])
            ->assertDispatched('delivery-data-updated');

        expect(app(DeliveryPricing::class)->quote(lga('Ikot Ekpene'))->fee)->toBe(1500.0)
            ->and(Activity::where('log_name', 'delivery')->latest('id')->first()->causer_id)->toBe($staff->id);
    });

    test('a fee rule without a fee is refused at the page', function () {
        setPermissionsTeamId(null);
        $staff = User::factory()->create();
        $staff->givePermissionTo('manage_delivery_settings');
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(DeliverySettings::class)
            ->call('saveLgaRule', lga('Abak')->id, ['mode' => 'fee', 'fee' => null])
            ->assertHasErrors(['fee']);

        expect(app(DeliveryPricing::class)->quote(lga('Abak'))->source)->toBe('state');
    });

    test('the catalogue editor and vendors cannot reach it', function () {
        setPermissionsTeamId(null);
        $editor = User::factory()->create();
        $editor->assignRole(CatalogueEditorRoleSeeder::ROLE);

        $this->actingAs($editor)->getJson(DeliverySettings::getUrl(panel: 'admin'))->assertForbidden();

        $owner = User::find(deliveryVendor()->user_id);
        $this->actingAs($owner)->getJson(DeliverySettings::getUrl(panel: 'admin'))->assertForbidden();
    });

    test('pinning a store as a hub from the page', function () {
        setPermissionsTeamId(null);
        $staff = User::factory()->create();
        $staff->givePermissionTo('manage_delivery_settings');
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $store = deliveryVendor()->defaultStore;

        Livewire::test(DeliverySettings::class)->call('setHub', $store->id, '5.0377', '7.9128');

        expect([(float) $store->fresh()->latitude, (float) $store->fresh()->longitude])->toBe([5.0377, 7.9128]);
    });
});

describe('legacy checkouts', function () {
    test('free-text areas map to an LGA; anything unrecognisable is reported, not guessed', function () {
        Illuminate\Support\Facades\Log::spy();

        $make = fn (string $area) => Checkout::create([
            'reference' => 'GP-OLD-'.uniqid(), 'customer_name' => 'Old', 'customer_phone' => '08011112222',
            'shipping_address' => $area, 'local_government' => $area, 'payment_method' => 'pay_on_delivery',
        ]);
        $known = $make('Mkpat Enin');
        $unknown = $make('Atlantis');

        (require database_path('migrations/2026_10_21_100001_seed_delivery_locations_and_map_legacy_areas.php'))->up();

        expect($known->fresh()->lga_id)->toBe(lga('Mkpat Enin')->id)
            ->and($known->fresh()->state_id)->toBe(state('Akwa Ibom')->id)
            ->and($unknown->fresh()->lga_id)->toBeNull()
            // Re-running is safe: still exactly 37 / 774.
            ->and(State::count())->toBe(37)->and(Lga::count())->toBe(774);

        Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(fn ($line) => str_contains($line, 'Unmapped: "Atlantis" ×1'))
            ->once();
    });
});

describe('Delivery settings, redesigned', function () {
    beforeEach(function () {
        setPermissionsTeamId(null);
        $staff = User::factory()->create();
        $staff->givePermissionTo('manage_delivery_settings');
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    test('the page renders its summary, map, hub and rule tables, and the price form', function () {
        $uyo = lga('Uyo');
        deliveryVendor()->defaultStore->update(['latitude' => $uyo->latitude, 'longitude' => $uyo->longitude]);
        DeliveryRule::setForLga($uyo, ['mode' => 'fee', 'fee' => 1500]);

        $this->get(DeliverySettings::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee('Areas we deliver to')
            ->assertSee('Hubs pinned')
            ->assertSee('Store locations (hubs)')
            ->assertSee('Rules by state and LGA')
            ->assertSee('Price by distance')
            ->assertSee('Not delivered')
            // Money as ₦1,500 — never Filament's "NGN 1,500.00". (Not "NGN" on
            // its own: the Meta pixel script names the currency that way.)
            ->assertDontSee('NGN 1', false)
            ->assertDontSee('1,500.00', false);

        expect(App\Filament\Pages\Delivery\DeliveryStats::summary())->toMatchArray(['paid' => 1, 'pinned' => 1]);
    });

    test('tapping an LGA on the map opens its rule, saves it, and resets it to the state default', function () {
        $uyo = lga('Uyo');

        Livewire::test(DeliverySettings::class)
            ->mountAction('editLga', ['lga' => $uyo->id])
            ->assertActionMounted('editLga')
            ->setActionData(['mode' => 'fee', 'fee' => 1200, 'expected_cost' => 800, 'delivery_days' => '1–2 days'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertDispatched('delivery-data-updated');

        expect(app(DeliveryPricing::class)->quote($uyo))->toMatchObject(['mode' => 'fee', 'fee' => 1200.0, 'source' => 'lga']);

        Livewire::test(DeliverySettings::class)->call('clearOverrides', [$uyo->id]);

        expect(app(DeliveryPricing::class)->quote($uyo)->source)->toBe('state');
    });

    test('a store is pinned from the hubs table, and a pin outside Nigeria is refused', function () {
        $store = deliveryVendor()->defaultStore;

        Livewire::test(App\Filament\Pages\Delivery\HubsTable::class)
            ->callTableAction('editCoordinates', $store, ['latitude' => 5.0377, 'longitude' => 7.9128])
            ->assertHasNoTableActionErrors()
            ->assertDispatched('delivery-rules-changed');

        expect([(float) $store->fresh()->latitude, (float) $store->fresh()->longitude])->toBe([5.0377, 7.9128]);

        // Latitude and longitude swapped: not in Nigeria.
        Livewire::test(App\Filament\Pages\Delivery\HubsTable::class)
            ->callTableAction('pinOnMap', $store, ['latitude' => 7.9128, 'longitude' => 51.5])
            ->assertHasTableActionErrors(['longitude']);

        // London, through the page's own write.
        Livewire::test(DeliverySettings::class)
            ->call('setHub', $store->id, '51.5', '-0.12')
            ->assertHasErrors(['lat', 'lng']);

        expect([(float) $store->fresh()->latitude, (float) $store->fresh()->longitude])->toBe([5.0377, 7.9128]);
    });

    test('the hubs table filters pinned and unpinned stores', function () {
        $pinned = deliveryVendor()->defaultStore;
        $pinned->update(['latitude' => 5.03, 'longitude' => 7.91]);
        $unpinned = deliveryVendor()->defaultStore;

        Livewire::test(App\Filament\Pages\Delivery\HubsTable::class)
            ->filterTable('pinned', true)
            ->assertCanSeeTableRecords([$pinned])
            ->assertCanNotSeeTableRecords([$unpinned])
            ->filterTable('pinned', false)
            ->assertCanSeeTableRecords([$unpinned])
            ->assertCanNotSeeTableRecords([$pinned]);
    });

    test('bulk actions set a rule for several LGAs, and clearing overrides needs a confirm', function () {
        $akwaIbom = state('Akwa Ibom');
        $picked = [lga('Uyo'), lga('Eket'), lga('Abak')];

        Livewire::test(App\Filament\Pages\Delivery\LgaRulesTable::class, ['stateId' => $akwaIbom->id])
            ->callTableBulkAction('setRule', $picked, ['mode' => 'fee', 'fee' => 2000, 'expected_cost' => 1500, 'delivery_days' => '2 days'])
            ->assertHasNoTableBulkActionErrors();

        foreach ($picked as $lga) {
            expect(app(DeliveryPricing::class)->quote($lga))->toMatchObject(['mode' => 'fee', 'fee' => 2000.0, 'source' => 'lga']);
        }

        Livewire::test(App\Filament\Pages\Delivery\LgaRulesTable::class, ['stateId' => $akwaIbom->id])
            ->assertTableBulkActionExists('clearOverrides', fn ($action) => $action->isConfirmationRequired())
            ->callTableBulkAction('clearOverrides', [$picked[0], $picked[1]]);

        expect(app(DeliveryPricing::class)->quote($picked[0])->source)->toBe('state')
            ->and(app(DeliveryPricing::class)->quote($picked[2])->source)->toBe('lga');
    });

    test('the rules table counts delivered LGAs per state and finds a state by an LGA name', function () {
        DeliveryRule::setForLga(lga('Uyo'), ['mode' => 'fee', 'fee' => 1500]);

        Livewire::test(App\Filament\Pages\Delivery\StateRulesTable::class)
            ->searchTable('Eket')
            ->assertCanSeeTableRecords([state('Akwa Ibom')])
            ->assertCanNotSeeTableRecords([state('Lagos')])
            ->assertSee('of 31 delivered');
    });

    test('price by distance: preview writes nothing, and apply is behind a confirm', function () {
        $uyo = lga('Uyo');
        deliveryVendor()->defaultStore->update(['latitude' => $uyo->latitude, 'longitude' => $uyo->longitude]);
        $before = DeliveryRule::orderBy('id')->get()->toArray();

        $page = Livewire::test(DeliverySettings::class)
            ->set('bandData.scope', state('Akwa Ibom')->id)
            ->callAction('previewBands');

        expect($page->get('bandPreview'))->not->toBeEmpty()
            ->and(DeliveryRule::orderBy('id')->get()->toArray())->toBe($before);

        $page->assertSee('LGAs would change')
            ->assertActionVisible('applyBands')
            ->assertActionExists('applyBands', fn ($action) => $action->isConfirmationRequired());

        // Mounting Apply only opens the confirm; nothing is written yet.
        $page->mountAction('applyBands');
        expect(DeliveryRule::orderBy('id')->get()->toArray())->toBe($before);

        $page->callMountedAction();

        expect(DeliveryRule::whereNotNull('lga_id')->count())->toBeGreaterThan(collect($before)->whereNotNull('lga_id')->count())
            ->and($page->get('bandPreview'))->toBe([]);
    });

    test('the delivery widgets refuse anyone the page refuses', function () {
        setPermissionsTeamId(null);
        $this->actingAs(User::factory()->create());

        // A 403, which the app turns into a redirect away (bootstrap/app.php).
        Livewire::test(App\Filament\Pages\Delivery\HubsTable::class)->assertRedirect()->assertDontSee('Store locations');
        Livewire::test(App\Filament\Pages\Delivery\StateRulesTable::class)->assertRedirect()->assertDontSee('Rules by state');
        Livewire::test(App\Filament\Pages\Delivery\LgaRulesTable::class, ['stateId' => state('Lagos')->id])->assertRedirect();
    });
});

describe('the customer\'s shared location at checkout', function () {
    test('checkout works exactly as before without a location', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);

        deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Ikot Ekpene'))
            ->call('processCheckout')
            ->assertHasNoErrors();

        $checkout = Checkout::latest('id')->first();

        expect($checkout->customer_latitude)->toBeNull()
            ->and($checkout->customer_longitude)->toBeNull()
            ->and((float) $checkout->delivery_fee)->toBe(1500.0);
    });

    test('a shared location is saved on the checkout — and the fee stays the LGA\'s, however far the pin is', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'fee', 'fee' => 1500]);

        // A pin in Lagos while the order says Ikot Ekpene.
        deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Ikot Ekpene'))
            ->call('setLocation', 6.4541, 3.3947, 18)
            ->assertSet('customerLat', 6.4541)
            ->call('processCheckout')
            ->assertHasNoErrors();

        $checkout = Checkout::latest('id')->first();

        expect($checkout->customer_latitude)->toBe(6.4541)
            ->and($checkout->customer_longitude)->toBe(3.3947)
            ->and($checkout->customer_location_accuracy)->toBe(18)
            ->and((float) $checkout->delivery_fee)->toBe(1500.0)
            ->and(app(App\Services\Orders\OrderLocation::class)->mismatched($checkout->orders()->first()))->toBeTrue();
    });

    test('a location outside Nigeria is left off, and the checkout still goes through', function () {
        DeliveryRule::setForLga(lga('Ikot Ekpene'), ['mode' => 'free']);

        deliveryCheckout([[deliveryProduct(deliveryVendor()), 1]], lga('Ikot Ekpene'))
            ->call('setLocation', 51.5072, -0.1276)
            ->assertSet('customerLat', null)
            ->assertSet('locationNote', fn (?string $note) => str_contains((string) $note, 'outside Nigeria'))
            ->call('processCheckout')
            ->assertHasNoErrors();

        expect(Checkout::latest('id')->first()->customer_latitude)->toBeNull();
    });
});
