<?php

use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformMessagingSetting;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setUpPlatformAlertVendor(array $settings = [], bool $seedTemplates = true): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Platform Alert Store']);

    if ($seedTemplates) {
        MessageTemplateSeeder::forVendor($vendor);
    }

    VendorNotificationSetting::forVendor($vendor)->update($settings);

    $category = Category::create(['name' => 'PA Cat '.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Bluetooth Speaker', 'price' => 20000, 'stock_quantity' => 30, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

function placeOnlineOrder(array $data, string $status = 'pending'): Order
{
    $order = Order::create([
        'reference' => 'GP-PA-'.strtoupper(uniqid()),
        'customer_name' => 'Ada Buyer',
        'customer_email' => 'ada@example.com',
        'customer_phone' => '08041110000',
        'shipping_address' => 'Uyo, Akwa Ibom State — 3 Market Road',
        'total_amount' => 20000,
        'status' => $status,
        'payment_method' => 'pay_on_delivery',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id,
        'vendor_id' => $data['vendor']->id, 'quantity' => 1, 'unit_price' => 20000,
    ]);

    return $order;
}

function alertsFor(Order $order, string $recipientType): \Illuminate\Database\Eloquent\Collection
{
    return DeliveryMessage::where('order_id', $order->id)
        ->where('recipient_type', $recipientType)
        ->get();
}

// --- Both messages on a paid online order -------------------------------

test('a paid online order alerts the storekeeper and confirms to the customer', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766']);
    $order = placeOnlineOrder($data);

    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper'))->toHaveCount(1)
        ->and(alertsFor($order, 'customer'))->toHaveCount(1);

    expect(alertsFor($order, 'storekeeper')->first()->body)->toContain('New order to pack')
        ->and(alertsFor($order, 'customer')->first()->body)->toContain('We have received your order');
});

// --- GadgetPlug fallback ------------------------------------------------

test('a vendor with no storekeeper number falls back to the GadgetPlug number', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => null]);
    PlatformMessagingSetting::current()->update(['fallback_storekeeper_whatsapp' => '08133334444']);

    $order = placeOnlineOrder($data);
    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper'))->toHaveCount(1)
        ->and(alertsFor($order, 'storekeeper')->first()->to_number)->toBe('2348133334444');
});

test('a vendor with their own number is never diverted to the fallback', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766']);
    PlatformMessagingSetting::current()->update(['fallback_storekeeper_whatsapp' => '08133334444']);

    $order = placeOnlineOrder($data);
    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper')->first()->to_number)->toBe('2348099887766');
});

// Only locked alerts fall back — an opt-in alert is the vendor's to receive, and
// routing it to the platform would send their traffic somewhere they never agreed.
test('an unlocked alert does not fall back to the platform number', function () {
    $data = setUpPlatformAlertVendor([
        'storekeeper_whatsapp' => null,
        'notify_cancelled' => true,
    ]);
    PlatformMessagingSetting::current()->update(['fallback_storekeeper_whatsapp' => '08133334444']);

    $order = placeOnlineOrder($data, 'paid');
    DeliveryMessage::truncate();

    $order->update(['status' => 'cancelled']);

    expect(DeliveryMessage::where('recipient_type', 'storekeeper')->count())->toBe(0);
});

test('no vendor number and no fallback sends nothing but does not error', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => null]);

    $order = placeOnlineOrder($data);
    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper'))->toBeEmpty()
        ->and($order->fresh()->status)->toBe('paid')
        // The customer confirmation is unaffected by the storekeeper gap.
        ->and(alertsFor($order, 'customer'))->toHaveCount(1);
});

// --- Locking ------------------------------------------------------------

test('a vendor switching off the new-order toggle cannot stop the pack alert', function () {
    $data = setUpPlatformAlertVendor([
        'storekeeper_whatsapp' => '08099887766',
        'notify_new_order' => false,
    ]);

    $order = placeOnlineOrder($data);
    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper'))->toHaveCount(1);
});

test('deactivating a locked template does not stop it sending', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766']);

    MessageTemplate::where('vendor_id', $data['vendor']->id)
        ->whereIn('key', MessageTemplate::PLATFORM_LOCKED_KEYS)
        ->update(['is_active' => false]);

    $order = placeOnlineOrder($data);
    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper'))->toHaveCount(1)
        ->and(alertsFor($order, 'customer'))->toHaveCount(1);
});

// An unlocked template must still honour is_active, or vendors lose all control.
test('deactivating an unlocked template still stops it sending', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766']);

    MessageTemplate::where('vendor_id', $data['vendor']->id)
        ->where('key', 'customer_dispatched')
        ->update(['is_active' => false]);

    $order = placeOnlineOrder($data, 'paid');
    DeliveryMessage::truncate();

    $order->update(['status' => 'shipped']);

    expect(alertsFor($order, 'customer'))->toBeEmpty();
});

// The exact failure that produced "0 sent, 5 skipped" in production: a vendor
// onboarded before a template existed has no row until someone runs the sync.
test('a vendor with no template rows at all still gets the locked messages', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766'], seedTemplates: false);

    expect(MessageTemplate::where('vendor_id', $data['vendor']->id)->count())->toBe(0);

    $order = placeOnlineOrder($data);
    $order->update(['status' => 'paid']);

    expect(alertsFor($order, 'storekeeper'))->toHaveCount(1)
        ->and(alertsFor($order, 'customer'))->toHaveCount(1)
        ->and(alertsFor($order, 'customer')->first()->body)->not->toContain('{{');
});

test('the fallback body is not persisted as a template row', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766'], seedTemplates: false);

    placeOnlineOrder($data)->update(['status' => 'paid']);

    expect(MessageTemplate::where('vendor_id', $data['vendor']->id)->count())->toBe(0);
});

test('a pay-on-delivery order confirming at checkout behaves identically', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766']);
    $order = placeOnlineOrder($data);

    $order->update(['status' => 'confirmed']);

    expect(alertsFor($order, 'storekeeper'))->toHaveCount(1)
        ->and(alertsFor($order, 'customer'))->toHaveCount(1);
});

test('an order left pending alerts nobody', function () {
    $data = setUpPlatformAlertVendor(['storekeeper_whatsapp' => '08099887766']);
    $order = placeOnlineOrder($data);

    $order->update(['shipping_address' => 'Uyo, Akwa Ibom State — 9 Other Road']);

    expect(DeliveryMessage::where('order_id', $order->id)->count())->toBe(0);
});
