<?php

use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Checkout;
use App\Models\DeliveryMessage;
use App\Models\Lga;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\WhatsAppGate;
use App\Services\Orders\DispatchMessages;
use App\Services\Orders\DispatchService;
use App\Services\Orders\OrderLocation;
use App\Services\Orders\OrderMessages;
use App\Services\Orders\OrderProgress;
use Database\Seeders\DispatcherRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

// GadgetPlug's order page: progress, messaging, the route map, and the
// customer's optional shared location (never shown anywhere public).

function orderPageDispatcher(): User
{
    (new DispatcherRoleSeeder)->run();

    $user = User::factory()->create();
    setPermissionsTeamId(null);
    $user->assignRole(DispatcherRoleSeeder::ROLE);

    return $user;
}

function actOnOrderPage(User $user): void
{
    test()->actingAs($user);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

/** Put the order in a checkout for Uyo, optionally with a shared pin. */
function orderInUyo(Order $order, ?float $lat = null, ?float $lng = null): Checkout
{
    $uyo = Lga::where('name', 'Uyo')->firstOrFail();

    $checkout = Checkout::create([
        'reference' => 'CK-'.uniqid(), 'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone,
        'shipping_address' => $order->shipping_address, 'payment_method' => $order->payment_method,
        'state_id' => $uyo->state_id, 'lga_id' => $uyo->id,
        'customer_latitude' => $lat, 'customer_longitude' => $lng,
        'items_total' => $order->total_amount, 'delivery_fee' => 0, 'grand_total' => $order->total_amount,
    ]);

    $order->updateQuietly(['checkout_id' => $checkout->id]);

    return $checkout;
}

// ── Progress ────────────────────────────────────────────────────────────────

it('steps through placed, confirmed, packed, with rider and delivered — who and when, the furthest one current', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');

    $steps = collect(app(OrderProgress::class)->steps($order->fresh()))->keyBy('key');

    expect($steps->pluck('state', 'key')->all())->toBe([
        'placed'    => OrderProgress::DONE,
        'confirmed' => OrderProgress::DONE,
        'packed'    => OrderProgress::DONE,
        'shipped'   => OrderProgress::CURRENT,
        'delivered' => OrderProgress::UPCOMING,
    ])
        ->and($steps['confirmed']['label'])->toBe('Confirmed')
        ->and($steps['packed']['by'])->toBe($c['owner']->name)
        ->and($steps['shipped']['at'])->not->toBeNull()
        ->and(app(OrderProgress::class)->branch($order))->toBeNull();
});

it('shows a return or a cancellation as a branch off the line, with the reason', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid');

    oss()->cancel($order, $c['admin'], 'Customer changed their mind.');
    $order = $order->fresh();

    $steps = collect(app(OrderProgress::class)->steps($order))->keyBy('key');
    $branch = app(OrderProgress::class)->branch($order);

    expect($steps['confirmed']['label'])->toBe('Paid')
        ->and($steps->where('state', OrderProgress::CURRENT))->toBeEmpty()
        ->and($branch)->toMatchArray(['key' => 'cancelled', 'reason' => 'Customer changed their mind.'])
        ->and($branch['by'])->toBe($c['admin']->name);
});

// ── Messages ────────────────────────────────────────────────────────────────

it('lists only recipients with a number: customer, rider/company, storekeeper, owner', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');

    expect(array_keys(app(OrderMessages::class)->recipients($order)))->toBe(['customer']);

    $c['main']->update(['phone' => '08031110000']);
    VendorNotificationSetting::forVendor($c['vendor'])->update(['owner_whatsapp' => '08032220000']);
    app(DispatchService::class)->assign($order, $c['admin'], App\Models\LogisticsCompany::create([
        'vendor_id' => null, 'name' => 'Swift', 'phone' => '08033330000', 'whatsapp' => '08033330000', 'is_active' => true,
    ])->id, cost: 1500, notify: false);

    $recipients = app(OrderMessages::class)->recipients($order->fresh());

    expect(array_keys($recipients))->toBe(['customer', 'rider', 'storekeeper', 'owner'])
        ->and($recipients['storekeeper']['number'])->toContain('8031110000')
        ->and($recipients['owner']['number'])->toContain('8032220000');
});

it('lets dispatch staff send a message — through MessagingService, filed as a manual transactional send', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    $dispatcher = orderPageDispatcher();

    $message = app(OrderMessages::class)->send($order, $dispatcher, 'customer', 'whatsapp', app(OrderMessages::class)->preset($order, 'delayed'));

    expect($message->status)->toBe('sent')
        ->and($message->order_id)->toBe($order->id)
        ->and($message->sent_by)->toBe($dispatcher->id)
        ->and($message->template_key)->toBe(OrderMessages::TEMPLATE_KEY)
        ->and($message->category)->toBe(DeliveryMessage::CATEGORY_TRANSACTIONAL)
        ->and($message->body)->toContain('running a little late');
});

it('refuses anyone without manage_dispatch, at the service and on the page', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');

    expect(fn () => app(OrderMessages::class)->send($order, $c['owner'], 'customer', 'whatsapp', 'Hello'))
        ->toThrow(AuthorizationException::class);

    expect(DeliveryMessage::where('order_id', $order->id)->where('template_key', OrderMessages::TEMPLATE_KEY)->exists())->toBeFalse();

    actOnOrderPage(orderPageDispatcher());
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('sendMessage')
        ->callAction('sendMessage', ['recipient' => 'customer', 'channel' => 'sms', 'body' => 'Rider is two streets away.'])
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $order->id)->where('body', 'Rider is two streets away.')->value('channel'))->toBe('sms');
});

it('still applies the WhatsApp brake to a message sent by hand', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    config(['services.messaging.whatsapp_driver' => 'wawp']);
    app(WhatsAppGate::class)->pause('Testing the brake.');

    $message = app(OrderMessages::class)->send($order, orderPageDispatcher(), 'customer', 'whatsapp', 'Hello');

    expect($message->status)->toBe('failed')
        ->and($message->provider_response['error'])->toBe('whatsapp_paused');
});

it('shows the message history of this order and its checkout\'s other parts — no one else\'s', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    $sibling = ossOrder($c, 'pay_on_delivery', 'confirmed', qty: 1);
    $stranger = ossOrder($c, 'pay_on_delivery', 'confirmed', qty: 1);
    $checkout = orderInUyo($order);
    $sibling->updateQuietly(['checkout_id' => $checkout->id]);

    $make = fn (Order $o, string $body) => DeliveryMessage::create([
        'vendor_id' => null, 'order_id' => $o->id, 'recipient_type' => 'customer', 'channel' => 'sms',
        'to_number' => '2348040000000', 'body' => $body, 'status' => 'sent',
    ]);
    $make($order, 'Mine');
    $make($sibling, 'Same checkout');
    $make($stranger, 'Someone else');

    $bodies = app(OrderMessages::class)->history($order->fresh())->pluck('body')->all();

    expect($bodies)->toContain('Mine', 'Same checkout')->not->toContain('Someone else');
});

it('opens a WhatsApp chat with the customer on wa.me', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');

    expect(OrderMessages::whatsappChatUrl($order))->toBe('https://wa.me/2348040000000');
});

// ── Map, directions, the shared location ────────────────────────────────────

it('maps the customer at their shared pin, or at the LGA centre labelled approximate', function () {
    $c = ossVendor();
    $c['main']->update(['latitude' => 5.0102, 'longitude' => 7.8505]);
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');

    orderInUyo($order);
    $approx = app(OrderLocation::class)->customer($order->fresh());

    expect($approx['precision'])->toBe(OrderLocation::APPROXIMATE)
        ->and($approx['label'])->toContain('Approximate (LGA centre)');

    $order->checkout->update(['customer_latitude' => 5.0401, 'customer_longitude' => 7.9302]);
    $exact = app(OrderLocation::class)->customer($order->fresh());

    expect($exact)->toMatchArray(['precision' => OrderLocation::EXACT, 'lat' => 5.0401, 'lng' => 7.9302])
        ->and(app(OrderLocation::class)->store($order)['lat'])->toBe(5.0102)
        ->and(app(OrderLocation::class)->km($order->fresh()))->toBeGreaterThan(0);
});

it('builds a Google Maps directions link from the store to the customer, with no key', function () {
    $c = ossVendor();
    $c['main']->update(['latitude' => 5.0102, 'longitude' => 7.8505]);
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    orderInUyo($order, 5.0401, 7.9302);

    expect(app(OrderLocation::class)->directionsUrl($order->fresh()))
        ->toBe('https://www.google.com/maps/dir/?api=1&origin=5.0102,7.8505&destination=5.0401,7.9302');
});

it('flags a shared pin more than 15 km from the chosen LGA, and the limit is configurable', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    $uyo = Lga::where('name', 'Uyo')->firstOrFail();

    orderInUyo($order, $uyo->latitude + 0.02, $uyo->longitude);   // about 2 km off
    expect(app(OrderLocation::class)->mismatched($order->fresh()))->toBeFalse();

    $order->checkout->update(['customer_latitude' => 6.45, 'customer_longitude' => 3.39]);   // Lagos
    expect(app(OrderLocation::class)->mismatched($order->fresh()))->toBeTrue();

    config(['delivery.location_mismatch_km' => 1000]);
    expect(app(OrderLocation::class)->mismatched($order->fresh()))->toBeFalse();

    // No shared pin: nothing to compare, nothing flagged.
    $order->checkout->update(['customer_latitude' => null, 'customer_longitude' => null]);
    config(['delivery.location_mismatch_km' => 15]);
    expect(app(OrderLocation::class)->mismatched($order->fresh()))->toBeFalse();
});

it('shows the mismatch on the order page and the dispatch board', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'confirmed');
    orderInUyo($order, 6.45, 3.39);
    actOnOrderPage(orderPageDispatcher());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('Location doesn&#039;t match LGA', false)
        ->assertSee('Progress')
        ->assertSee('Messages');

    Livewire::test(App\Filament\Resources\Orders\Pages\ListOrders::class)
        ->assertTableColumnStateSet('location_check', "Location doesn't match LGA", $order);
});

it('gives the rider link the directions button, and never puts the exact pin on /track', function () {
    $c = ossVendor();
    $c['main']->update(['latitude' => 5.0102, 'longitude' => 7.8505]);
    $order = ossOrder($c, 'pay_on_delivery', 'shipped');
    orderInUyo($order, 5.0401, 7.9302);
    app(DispatchService::class)->assign($order, $c['admin'], App\Models\LogisticsCompany::create([
        'vendor_id' => null, 'name' => 'Swift', 'phone' => '08033330000', 'is_active' => true,
    ])->id, cost: 1500, notify: false);

    $this->get(DispatchMessages::link($order->fresh()))
        ->assertOk()
        ->assertSee('Open directions in Google Maps')
        ->assertSee('destination=5.0401,7.9302', false);

    \Livewire\Volt\Volt::test('pages.track-order')
        ->set('reference', $order->reference)
        ->call('lookup')
        ->assertSee($order->reference)
        ->assertDontSee('5.0401')
        ->assertDontSee('7.9302');
});
