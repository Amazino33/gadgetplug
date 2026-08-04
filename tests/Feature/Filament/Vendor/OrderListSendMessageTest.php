<?php

use App\Filament\Vendor\Resources\Orders\Pages\ListOrders;
use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpListMessagingVendor(string $storeName = 'List Messaging Store'): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $storeName]);
    MessageTemplateSeeder::forVendor($vendor);

    $category = Category::create(['name' => 'List Cat '.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Bluetooth Speaker', 'price' => 25000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'GP-LIST-'.strtoupper(uniqid()),
        'customer_name' => 'Ada Customer',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => 'Uyo, Akwa Ibom State — 5 Test Close',
        'total_amount' => 25000,
        'status' => 'paid',
        'payment_method' => 'pay_on_delivery',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => 1, 'unit_price' => 25000,
    ]);

    return compact('owner', 'vendor', 'order');
}

function actAsListVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('the list page offers the vendor customer templates pre-rendered for that order', function () {
    $data = setUpListMessagingVendor();
    actAsListVendor($data);

    $templates = Livewire::test(ListOrders::class)->instance()->messageTemplatesFor($data['order']);

    expect($templates)->not->toBeEmpty();

    $received = collect($templates)->firstWhere('key', 'customer_received');

    expect($received)->not->toBeNull()
        ->and($received['label'])->toBe('Customer Received')
        ->and($received['channel'])->toBe('whatsapp')
        ->and($received['body'])->toContain('Hello Ada Customer')
        ->and($received['body'])->toContain('1 x Bluetooth Speaker — ₦25,000.00')
        ->and($received['body'])->toContain('Uyo, Akwa Ibom State — 5 Test Close')
        ->and($received['body'])->not->toContain('{{');
});

test('rider-only templates are not offered as customer messages', function () {
    $data = setUpListMessagingVendor();
    actAsListVendor($data);

    $keys = collect(Livewire::test(ListOrders::class)->instance()->messageTemplatesFor($data['order']))
        ->pluck('key');

    expect($keys)->not->toContain('rider_assignment');
});

test('an inactive template is not offered', function () {
    $data = setUpListMessagingVendor();
    actAsListVendor($data);

    MessageTemplate::where('vendor_id', $data['vendor']->id)
        ->where('key', 'customer_delivered')
        ->update(['is_active' => false]);

    $keys = collect(Livewire::test(ListOrders::class)->instance()->messageTemplatesFor($data['order']))
        ->pluck('key');

    expect($keys)->not->toContain('customer_delivered')
        ->and($keys)->toContain('customer_received');
});

test('sending from the list logs the message against the order and delivers it', function () {
    $data = setUpListMessagingVendor();
    actAsListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('sendOrderMessage', $data['order']->id, 'Hello Ada, your speaker is ready.', 'whatsapp');

    $message = DeliveryMessage::where('order_id', $data['order']->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->vendor_id)->toBe($data['vendor']->id)
        ->and($message->recipient_type)->toBe('customer')
        ->and($message->channel)->toBe('whatsapp')
        ->and($message->to_number)->toBe('2348040000000')
        ->and($message->body)->toBe('Hello Ada, your speaker is ready.')
        ->and($message->sent_by)->toBe($data['owner']->id)
        ->and($message->status)->toBe('sent');
});

test('an empty message body sends nothing', function () {
    $data = setUpListMessagingVendor();
    actAsListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('sendOrderMessage', $data['order']->id, '   ', 'whatsapp');

    expect(DeliveryMessage::where('order_id', $data['order']->id)->count())->toBe(0);
});

test('an unrecognised channel falls back to whatsapp rather than failing the row insert', function () {
    $data = setUpListMessagingVendor();
    actAsListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('sendOrderMessage', $data['order']->id, 'Body', 'carrier-pigeon');

    expect(DeliveryMessage::where('order_id', $data['order']->id)->first()->channel)->toBe('whatsapp');
});

// The order id crosses the wire from the browser, so it is attacker-controlled.
// The lookup is scoped to the tenant, so a foreign id resolves to nothing and
// raises ModelNotFoundException — a 404 over HTTP.
test('a vendor cannot send a message on another vendors order', function () {
    $mine = setUpListMessagingVendor('My Store');
    $theirs = setUpListMessagingVendor('Their Store');

    actAsListVendor($mine);

    expect(fn () => Livewire::test(ListOrders::class)
        ->call('sendOrderMessage', $theirs['order']->id, 'Leaked message', 'whatsapp'))
        ->toThrow(ModelNotFoundException::class);

    expect(DeliveryMessage::where('order_id', $theirs['order']->id)->count())->toBe(0);
});

test('a vendor cannot change the status of another vendors order', function () {
    $mine = setUpListMessagingVendor('My Store');
    $theirs = setUpListMessagingVendor('Their Store');

    actAsListVendor($mine);

    expect(fn () => Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $theirs['order']->id, 'shipped', null))
        ->toThrow(ModelNotFoundException::class);

    expect($theirs['order']->fresh()->status)->toBe('paid');
});
