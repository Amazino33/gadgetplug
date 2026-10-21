<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder as VendorViewOrder;
use App\Models\DeliveryMessage;
use App\Models\PlatformMessagingSetting;
use App\Models\User;
use App\Models\VendorNotificationSetting;
use App\Services\Orders\DispatchService;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Orders/Helpers.php';

// Phase 5 routing: who each message goes to.

function storekeeperAt(array $c, App\Models\Store $store, string $phone): User
{
    (new VendorPermissionsSeeder)->run();
    VendorRoles::seedFor($c['vendor']);

    $keeper = User::factory()->create(['phone' => $phone]);
    $keeper->stores()->attach($store->id);
    $c['vendor']->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($c['vendor']->id);
    $keeper->assignRole('storekeeper');

    return $keeper;
}

function storekeeperAlertsFor(App\Models\Order $order)
{
    return DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'storekeeper')->pluck('to_number')->all();
}

// ── Storekeeper, per allocated store ────────────────────────────────────────

it('sends the pack alert to the storekeeper of the store holding the stock, not another branch', function () {
    $c = ossVendor();
    storekeeperAt($c, $c['branch'], '08070000001');
    storekeeperAt($c, $c['main'], '08070000002');
    VendorNotificationSetting::forVendor($c['vendor'])->update(['storekeeper_whatsapp' => '08070000009']);

    $order = ossOrder($c, 'pay_on_delivery', 'confirmed', 1, $c['branch']);

    expect(storekeeperAlertsFor($order))->toBe(['2348070000001']);
});

it('falls back from storekeeper to the store\'s phone, then the vendor number, then GadgetPlug\'s', function () {
    $c = ossVendor();

    // No storekeeper at the branch, but the branch has a phone.
    $c['branch']->update(['phone' => '08071111111']);
    $atBranch = ossOrder($c, 'pay_on_delivery', 'confirmed', 1, $c['branch']);

    // Main has neither: the vendor's storekeeper number.
    VendorNotificationSetting::forVendor($c['vendor'])->update(['storekeeper_whatsapp' => '08072222222']);
    $atMain = ossOrder($c, 'pay_on_delivery', 'confirmed', 1, $c['main']);

    // Nothing at all from the vendor: GadgetPlug's fallback (the alert is platform-locked).
    VendorNotificationSetting::forVendor($c['vendor'])->update(['storekeeper_whatsapp' => null]);
    PlatformMessagingSetting::current()->update(['fallback_storekeeper_whatsapp' => '08073333333']);
    $fallback = ossOrder($c, 'pay_on_delivery', 'confirmed', 1, $c['main']);

    expect(storekeeperAlertsFor($atBranch))->toBe(['2348071111111'])
        ->and(storekeeperAlertsFor($atMain))->toBe(['2348072222222'])
        ->and(storekeeperAlertsFor($fallback))->toBe(['2348073333333']);
});

// ── Vendor owner ────────────────────────────────────────────────────────────

it('sends the vendor owner a WhatsApp new-order alert when an owner number is set', function () {
    $c = ossVendor();
    VendorNotificationSetting::forVendor($c['vendor'])->update(['owner_whatsapp' => '08074444444']);

    $order = ossOrder($c, 'paystack', 'paid', 1);
    $alert = DeliveryMessage::where('order_id', $order->id)->where('template_key', 'vendor_new_order')->sole();

    expect($alert->to_number)->toBe('2348074444444')
        ->and($alert->vendor_id)->toBe($c['vendor']->id)
        ->and((int) $alert->priority)->toBe(DeliveryMessage::P2)
        ->and($alert->body)->toContain($order->reference);
});

it('sends no owner alert without an owner number', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid', 1);

    expect(DeliveryMessage::where('order_id', $order->id)->where('template_key', 'vendor_new_order')->exists())->toBeFalse();
});

// ── Dispatch: packed but not handed over ────────────────────────────────────

it('reminds admin dispatch — not the storekeeper — about orders packed but not handed over', function () {
    $c = ossVendor();
    config(['messaging.ops_number' => '08075555555']);
    VendorNotificationSetting::forVendor($c['vendor'])->update(['storekeeper_whatsapp' => '08076666666']);
    $order = ossOrder($c, 'paystack', 'packed', 1);
    $order->updateQuietly(['packed_at' => now()->subHours(3)]);
    $before = DeliveryMessage::where('to_number', '2348076666666')->count();

    $this->artisan('dispatch:remind-packed')->assertSuccessful();

    $reminder = DeliveryMessage::where('template_key', 'dispatch_packed_reminder')->sole();

    expect($reminder->to_number)->toBe('2348075555555')
        ->and($reminder->vendor_id)->toBeNull()
        ->and((int) $reminder->priority)->toBe(DeliveryMessage::P3)
        ->and($reminder->body)->toContain($order->reference)
        ->and($c['admin']->notifications()->count())->toBe(1)
        ->and(DeliveryMessage::where('to_number', '2348076666666')->count())->toBe($before);

    // Not again within the interval.
    $this->artisan('dispatch:remind-packed')->assertSuccessful();
    expect(DeliveryMessage::where('template_key', 'dispatch_packed_reminder')->count())->toBe(1);

    $this->travel(4)->hours();
    $this->artisan('dispatch:remind-packed')->assertSuccessful();
    expect(DeliveryMessage::where('template_key', 'dispatch_packed_reminder')->count())->toBe(2);
});

// ── The handover message ────────────────────────────────────────────────────

it('sends one customer message at handover with the code, and the code appears nowhere else', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'packed');
    app(DispatchService::class)->assign($order, $c['admin'], LogisticsCompanyFor(), riderPhone: '08077777777');

    oss()->handOver($order->fresh(), $c['owner']);

    $customer = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')
        ->where('created_at', '>=', now()->subMinute())->get()
        ->filter(fn ($m) => str_contains($m->body, 'delivery code'));

    expect($customer)->toHaveCount(1);

    preg_match('/delivery code is \*(\d{4})\*/', $customer->first()->body, $m);
    $code = $m[1];

    expect($customer->first()->body)->toContain('is on its way')
        ->and($customer->first()->vendor_id)->toBeNull()
        ->and((int) $customer->first()->priority)->toBe(DeliveryMessage::P1)
        ->and(DeliveryMessage::where('id', '!=', $customer->first()->id)->get()->filter(fn ($row) => str_contains($row->body, $code)))->toBeEmpty()
        // The rider link is GadgetPlug's too: filed under no vendor.
        ->and(DeliveryMessage::where('template_key', 'logistics_dispatch')->pluck('vendor_id')->unique()->all())->toBe([null]);
});

function LogisticsCompanyFor(): int
{
    return App\Models\LogisticsCompany::create(['vendor_id' => null, 'name' => 'Partner '.uniqid(), 'phone' => '08078888888', 'is_active' => true])->id;
}

// ── Vendor page: message the rider ──────────────────────────────────────────

it('lets the vendor message the rider GadgetPlug assigned, on the order\'s rider phone', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped', 1);
    app(DispatchService::class)->assign($order, $c['admin'], LogisticsCompanyFor(), riderName: 'Emeka', riderPhone: '08079999999', notify: false);

    $this->actingAs($c['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($c['vendor']);

    Livewire::test(VendorViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData(['recipient_type' => 'rider', 'channel' => 'whatsapp', 'body' => 'The customer is at the gate.'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'rider')->latest('id')->value('to_number'))
        ->toBe('2348079999999');
});

// ── Customer-first chat ─────────────────────────────────────────────────────

it('offers a "Get updates on WhatsApp" chat on the success screen, the order reference pre-filled', function () {
    config(['messaging.automation_whatsapp' => '08012345678']);
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed', 1);
    $checkout = App\Models\Checkout::create([
        'reference' => $order->reference, 'customer_name' => 'Ada', 'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street', 'payment_method' => 'pay_on_delivery',
        'items_total' => 5000, 'delivery_fee' => 0, 'grand_total' => 5000,
    ]);
    $order->updateQuietly(['checkout_id' => $checkout->id]);

    Illuminate\Support\Facades\Session::put('payment_success', $checkout->reference);

    Volt::test('checkout')
        ->assertSee('Get updates on WhatsApp')
        ->assertSee('https://wa.me/2348012345678?text='.rawurlencode('Hi GadgetPlug, my order is '.$checkout->reference), false);
});

it('hides the WhatsApp button when no automation number is configured', function () {
    config(['messaging.automation_whatsapp' => null]);
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed', 1);
    $checkout = App\Models\Checkout::create([
        'reference' => $order->reference, 'customer_name' => 'Ada', 'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street', 'payment_method' => 'pay_on_delivery',
        'items_total' => 5000, 'delivery_fee' => 0, 'grand_total' => 5000,
    ]);
    $order->updateQuietly(['checkout_id' => $checkout->id]);

    Illuminate\Support\Facades\Session::put('payment_success', $checkout->reference);

    Volt::test('checkout')->assertDontSee('Get updates on WhatsApp');
});
