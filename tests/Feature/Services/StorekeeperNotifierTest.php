<?php

use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\StorekeeperNotifier;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function setUpStorekeeperVendor(array $settings = []): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Storekeeper Test Store']);
    MessageTemplateSeeder::forVendor($vendor);

    VendorNotificationSetting::forVendor($vendor)->update(array_merge([
        'storekeeper_whatsapp' => '08099887766',
    ], $settings));

    $category = Category::create(['name' => 'SK Cat '.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Power Bank', 'price' => 15000, 'stock_quantity' => 20,
        'low_stock_threshold' => 5, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

function makeStorekeeperOrder(array $data, string $status = 'pending', int $qty = 2): Order
{
    $order = Order::create([
        'reference' => 'GP-SK-'.strtoupper(uniqid()),
        'customer_name' => 'Bola Buyer',
        'customer_email' => 'bola@example.com',
        'customer_phone' => '08041110000',
        'shipping_address' => 'Uyo, Akwa Ibom State — 3 Market Road',
        'total_amount' => 15000 * $qty,
        'status' => $status,
        'payment_method' => 'pay_on_delivery',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id,
        'vendor_id' => $data['vendor']->id, 'quantity' => $qty, 'unit_price' => 15000,
    ]);

    return $order;
}

function storekeeperMessages(): \Illuminate\Database\Eloquent\Collection
{
    return DeliveryMessage::where('recipient_type', 'storekeeper')->get();
}

// --- New order alert ----------------------------------------------------

test('paying an order alerts the storekeeper with items, customer and address', function () {
    $data = setUpStorekeeperVendor();
    $order = makeStorekeeperOrder($data);

    $order->update(['status' => 'paid']);

    $message = storekeeperMessages()->firstWhere('order_id', $order->id);

    expect($message)->not->toBeNull()
        ->and($message->to_number)->toBe('2348099887766')
        ->and($message->channel)->toBe('whatsapp')
        ->and($message->status)->toBe('sent')
        ->and($message->body)->toContain('New order to pack')
        ->and($message->body)->toContain($order->reference)
        ->and($message->body)->toContain('2 x Power Bank')
        ->and($message->body)->toContain('Bola Buyer')
        ->and($message->body)->toContain('3 Market Road')
        ->and($message->body)->toContain('Storekeeper Test Store')
        ->and($message->body)->not->toContain('{{');
});

test('the customer and the storekeeper both get their own message on payment', function () {
    $data = setUpStorekeeperVendor();
    $order = makeStorekeeperOrder($data);

    $order->update(['status' => 'paid']);

    $byRecipient = DeliveryMessage::where('order_id', $order->id)->pluck('recipient_type');

    expect($byRecipient)->toContain('customer')
        ->and($byRecipient)->toContain('storekeeper');
});

test('no storekeeper number means no storekeeper alert', function () {
    $data = setUpStorekeeperVendor(['storekeeper_whatsapp' => null]);
    $order = makeStorekeeperOrder($data);

    $order->update(['status' => 'paid']);

    expect(storekeeperMessages())->toBeEmpty();
});

test('the new-order toggle switched off suppresses the alert', function () {
    $data = setUpStorekeeperVendor(['notify_new_order' => false]);
    $order = makeStorekeeperOrder($data);

    $order->update(['status' => 'paid']);

    expect(storekeeperMessages())->toBeEmpty();
});

test('an inactive storekeeper template suppresses the alert', function () {
    $data = setUpStorekeeperVendor();
    MessageTemplate::where('vendor_id', $data['vendor']->id)
        ->where('key', 'storekeeper_new_order')
        ->update(['is_active' => false]);

    makeStorekeeperOrder($data)->update(['status' => 'paid']);

    expect(storekeeperMessages())->toBeEmpty();
});

test('cancellation alerts the storekeeper only when that toggle is on', function () {
    $data = setUpStorekeeperVendor(['notify_cancelled' => true]);
    $order = makeStorekeeperOrder($data, 'paid');

    $order->update(['status' => 'cancelled']);

    expect(storekeeperMessages()->last()->body)->toContain('Order cancelled');
});

test('cancellation sends nothing when the cancelled toggle is off', function () {
    $data = setUpStorekeeperVendor(['notify_cancelled' => false]);
    $order = makeStorekeeperOrder($data, 'pending');

    $order->update(['status' => 'cancelled']);

    expect(storekeeperMessages())->toBeEmpty();
});

// An internal alert must never break the customer's order flow. The storekeeper
// template alone is pointed at an unsupported channel, so only that send breaks.
test('a storekeeper alert failure does not stop the status change or the customer message', function () {
    $data = setUpStorekeeperVendor();

    MessageTemplate::where('vendor_id', $data['vendor']->id)
        ->where('key', 'storekeeper_new_order')
        ->update(['channel' => 'carrier-pigeon']);

    $order = makeStorekeeperOrder($data);
    $order->update(['status' => 'paid']);

    expect($order->fresh()->status)->toBe('paid')
        ->and(DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')->first()?->status)
        ->toBe('sent')
        ->and(storekeeperMessages()->first()->status)->toBe('failed');
});

// --- Undispatched digest ------------------------------------------------

test('the reminder digests every stalled order into one message', function () {
    $data = setUpStorekeeperVendor(['undispatched_after_hours' => 6]);

    $stale1 = makeStorekeeperOrder($data, 'paid');
    $stale2 = makeStorekeeperOrder($data, 'confirmed');
    $fresh  = makeStorekeeperOrder($data, 'paid');

    // updated_at is what the cutoff compares, so age the two stale rows directly.
    Order::whereIn('id', [$stale1->id, $stale2->id])->update(['updated_at' => now()->subHours(30)]);

    DeliveryMessage::truncate();

    $message = app(StorekeeperNotifier::class)->undispatchedReminder($data['vendor']->fresh());

    expect($message)->not->toBeNull()
        ->and($message->order_id)->toBeNull()
        ->and($message->body)->toContain('2 order(s) still awaiting dispatch')
        ->and($message->body)->toContain($stale1->reference)
        ->and($message->body)->toContain($stale2->reference)
        ->and($message->body)->not->toContain($fresh->reference)
        ->and($message->body)->not->toContain('{{');

    expect(storekeeperMessages())->toHaveCount(1);
});

// The whole point of the activation watermark: an existing store's historical
// backlog of paid-but-never-shipped orders must not be dragged into the digest.
test('orders placed before activation are never chased', function () {
    $data = setUpStorekeeperVendor(['undispatched_after_hours' => 6]);

    $settings = VendorNotificationSetting::forVendor($data['vendor']);
    expect($settings->remind_orders_from)->not->toBeNull();

    $old = makeStorekeeperOrder($data, 'paid');
    Order::whereKey($old->id)->update([
        'created_at' => $settings->remind_orders_from->copy()->subMonths(3),
        'updated_at' => now()->subMonths(3),
    ]);

    expect(app(StorekeeperNotifier::class)->undispatchedReminder($data['vendor']))->toBeNull();
});

test('an order placed after activation is still chased normally', function () {
    $data = setUpStorekeeperVendor(['undispatched_after_hours' => 6]);
    $settings = VendorNotificationSetting::forVendor($data['vendor']);

    $new = makeStorekeeperOrder($data, 'paid');
    Order::whereKey($new->id)->update([
        'created_at' => $settings->remind_orders_from->copy()->addMinutes(5),
        'updated_at' => now()->subDay(),
    ]);

    DeliveryMessage::truncate();

    $message = app(StorekeeperNotifier::class)->undispatchedReminder($data['vendor']);

    expect($message)->not->toBeNull()
        ->and($message->body)->toContain($new->reference);
});

test('clearing the watermark lets the whole backlog be chased once', function () {
    $data = setUpStorekeeperVendor(['undispatched_after_hours' => 6, 'remind_orders_from' => null]);

    $old = makeStorekeeperOrder($data, 'paid');
    Order::whereKey($old->id)->update([
        'created_at' => now()->subMonths(3),
        'updated_at' => now()->subMonths(3),
    ]);

    DeliveryMessage::truncate();

    expect(app(StorekeeperNotifier::class)->undispatchedReminder($data['vendor'])?->body)
        ->toContain($old->reference);
});

test('the reminder sends nothing when every order is inside the follow-up window', function () {
    $data = setUpStorekeeperVendor(['undispatched_after_hours' => 6]);
    makeStorekeeperOrder($data, 'paid');

    expect(app(StorekeeperNotifier::class)->undispatchedReminder($data['vendor']))->toBeNull();
});

test('shipped and delivered orders never appear in the reminder', function () {
    $data = setUpStorekeeperVendor();
    $shipped = makeStorekeeperOrder($data, 'shipped');
    Order::whereKey($shipped->id)->update(['updated_at' => now()->subDays(3)]);

    expect(app(StorekeeperNotifier::class)->undispatchedReminder($data['vendor']))->toBeNull();
});

test('a vendor is never told about another vendors stalled orders', function () {
    $mine = setUpStorekeeperVendor();
    $theirs = setUpStorekeeperVendor();

    $theirOrder = makeStorekeeperOrder($theirs, 'paid');
    Order::whereKey($theirOrder->id)->update(['updated_at' => now()->subDays(2)]);

    expect(app(StorekeeperNotifier::class)->undispatchedReminder($mine['vendor']))->toBeNull();
});

// --- Low stock ----------------------------------------------------------

test('the low stock alert lists products at or below their threshold', function () {
    $data = setUpStorekeeperVendor(['notify_low_stock' => true]);
    $data['product']->update(['stock_quantity' => 3]);

    $message = app(StorekeeperNotifier::class)->lowStockAlert($data['vendor']);

    expect($message)->not->toBeNull()
        ->and($message->body)->toContain('Low stock — 1 product(s)')
        ->and($message->body)->toContain('Power Bank — 3 left')
        ->and($message->body)->not->toContain('{{');
});

test('the low stock alert respects its toggle', function () {
    $data = setUpStorekeeperVendor(['notify_low_stock' => false]);
    $data['product']->update(['stock_quantity' => 1]);

    expect(app(StorekeeperNotifier::class)->lowStockAlert($data['vendor']))->toBeNull();
});

test('a well-stocked shelf sends no low stock alert', function () {
    $data = setUpStorekeeperVendor(['notify_low_stock' => true]);

    expect(app(StorekeeperNotifier::class)->lowStockAlert($data['vendor']))->toBeNull();
});

// --- Cadence and quiet hours -------------------------------------------

test('a reminder is due when it has never been sent', function () {
    $data = setUpStorekeeperVendor();

    expect(VendorNotificationSetting::forVendor($data['vendor'])->reminderDueAt(Carbon::parse('2026-08-06 10:00')))
        ->toBeTrue();
});

test('the configured frequency gates how often a reminder repeats', function () {
    $data = setUpStorekeeperVendor([
        'reminder_frequency' => '6h',
        'last_reminder_sent_at' => Carbon::parse('2026-08-06 10:00'),
    ]);

    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    expect($settings->reminderDueAt(Carbon::parse('2026-08-06 13:00')))->toBeFalse()
        ->and($settings->reminderDueAt(Carbon::parse('2026-08-06 16:00')))->toBeTrue();
});

test('quiet hours hold reminders back outside the waking window', function () {
    $data = setUpStorekeeperVendor([
        'quiet_hours_enabled' => true,
        'quiet_from' => '08:00:00',
        'quiet_until' => '20:00:00',
    ]);

    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    // Quiet hours are the shop's hours, so the boundaries are stated in its clock.
    expect($settings->isQuietAt(Carbon::parse('2026-08-06 03:00', 'Africa/Lagos')))->toBeTrue()
        ->and($settings->isQuietAt(Carbon::parse('2026-08-06 07:59', 'Africa/Lagos')))->toBeTrue()
        ->and($settings->isQuietAt(Carbon::parse('2026-08-06 08:00', 'Africa/Lagos')))->toBeFalse()
        ->and($settings->isQuietAt(Carbon::parse('2026-08-06 19:59', 'Africa/Lagos')))->toBeFalse()
        ->and($settings->isQuietAt(Carbon::parse('2026-08-06 20:00', 'Africa/Lagos')))->toBeTrue();
});

test('a waking window that wraps midnight is handled', function () {
    $data = setUpStorekeeperVendor([
        'quiet_hours_enabled' => true,
        'quiet_from' => '20:00:00',
        'quiet_until' => '08:00:00',
    ]);

    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    // Waking period runs 20:00 through 08:00; quiet is the daytime gap.
    expect($settings->isQuietAt(Carbon::parse('2026-08-06 22:00')))->toBeFalse()
        ->and($settings->isQuietAt(Carbon::parse('2026-08-06 03:00')))->toBeFalse()
        ->and($settings->isQuietAt(Carbon::parse('2026-08-06 12:00')))->toBeTrue();
});

test('quiet hours switched off never suppresses anything', function () {
    $data = setUpStorekeeperVendor(['quiet_hours_enabled' => false]);

    expect(VendorNotificationSetting::forVendor($data['vendor'])->fresh()->isQuietAt(Carbon::parse('2026-08-06 03:00')))
        ->toBeFalse();
});

test('reminders are not due when both recurring toggles are off', function () {
    $data = setUpStorekeeperVendor(['notify_undispatched' => false, 'notify_low_stock' => false]);

    expect(VendorNotificationSetting::forVendor($data['vendor'])->fresh()->reminderDueAt(Carbon::now()))
        ->toBeFalse();
});

// --- Scheduled command -------------------------------------------------

test('the reminder command sends and stamps the cadence clock', function () {
    $data = setUpStorekeeperVendor(['undispatched_after_hours' => 6]);
    $order = makeStorekeeperOrder($data, 'paid');
    Order::whereKey($order->id)->update(['updated_at' => now()->subDays(1)]);

    DeliveryMessage::truncate();

    $this->artisan('storekeeper:remind')->assertSuccessful();

    expect(storekeeperMessages())->toHaveCount(1)
        ->and(VendorNotificationSetting::forVendor($data['vendor'])->fresh()->last_reminder_sent_at)
        ->not->toBeNull();
});

// Otherwise a stall right after a quiet cycle would wait a full extra period.
test('a run with nothing to report leaves the cadence clock untouched', function () {
    $data = setUpStorekeeperVendor();
    makeStorekeeperOrder($data, 'paid');

    $this->artisan('storekeeper:remind')->assertSuccessful();

    expect(VendorNotificationSetting::forVendor($data['vendor'])->fresh()->last_reminder_sent_at)
        ->toBeNull();
});

test('the command respects quiet hours', function () {
    // Frozen before setup, not just before the order: forVendor() stamps
    // remind_orders_from with now(), and a watermark on the real calendar would
    // filter out an order dated on the frozen one.
    Carbon::setTestNow(Carbon::parse('2026-08-06 03:00'));

    $data = setUpStorekeeperVendor([
        'quiet_hours_enabled' => true,
        'quiet_from' => '08:00:00',
        'quiet_until' => '20:00:00',
    ]);
    $order = makeStorekeeperOrder($data, 'paid');
    Order::whereKey($order->id)->update(['updated_at' => now()->subDays(1)]);

    DeliveryMessage::truncate();

    $this->artisan('storekeeper:remind')->assertSuccessful();

    expect(storekeeperMessages())->toBeEmpty();

    Carbon::setTestNow();
});

test('force ignores cadence and quiet hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-06 03:00'));

    $data = setUpStorekeeperVendor([
        'quiet_hours_enabled' => true,
        'quiet_from' => '08:00:00',
        'quiet_until' => '20:00:00',
    ]);
    $order = makeStorekeeperOrder($data, 'paid');
    Order::whereKey($order->id)->update(['updated_at' => now()->subDays(1)]);

    DeliveryMessage::truncate();

    $this->artisan('storekeeper:remind --force')->assertSuccessful();

    expect(storekeeperMessages())->toHaveCount(1);

    Carbon::setTestNow();
});
