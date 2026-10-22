<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Jobs\SendDeliveryMessageJob;
use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Automated messages (status changes, reminders, summaries) go through
// SendDeliveryMessageJob so a slow gateway never holds up checkout. Messages a
// person sends by hand from the order page stay synchronous.

function queuedMessagingOrder(string $status = 'pending'): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Queued Messaging Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Queued Messaging Category '.uniqid()]);
    $product  = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Queued Widget', 'price' => 5000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'GP-Q-'.uniqid(), 'customer_name' => 'Queue Buyer',
        'customer_email' => 'q@example.com', 'customer_phone' => '08060000000',
        'shipping_address' => 'Uyo', 'total_amount' => 5000,
        'status' => $status, 'payment_method' => 'pay_on_delivery',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $vendor->id, 'quantity' => 1, 'unit_price' => 5000,
    ]);

    return compact('owner', 'vendor', 'order');
}

test('an automated status-change message is queued on the messaging queue, not sent inline', function () {
    Queue::fake();
    Http::fake();
    // A real HTTP driver, so an inline send would show up as a request.
    config(['services.messaging.whatsapp_driver' => 'wawp', 'services.wawp.instance_id' => 'i', 'services.wawp.access_token' => 't']);

    $c = queuedMessagingOrder();
    $c['order']->update(['status' => 'confirmed']);

    $message = DeliveryMessage::where('order_id', $c['order']->id)->where('recipient_type', 'customer')->sole();

    expect($message->status)->toBe('queued');
    // The confirmation is urgent (P1): its WhatsApp lane is drained first.
    Queue::assertPushedOn('messaging-p1', SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $message->id);
    Http::assertNothingSent();
});

test('the job sends the queued message and marks it sent', function () {
    config(['services.messaging.whatsapp_driver' => 'log_null']);
    Queue::fake();

    $c = queuedMessagingOrder();
    $c['order']->update(['status' => 'confirmed']);
    $message = DeliveryMessage::where('order_id', $c['order']->id)->where('recipient_type', 'customer')->sole();

    (new SendDeliveryMessageJob($message->id))->withFakeQueueInteractions()->handle(app(\App\Services\Messaging\MessagingService::class));

    expect($message->fresh()->status)->toBe('sent')
        ->and($message->fresh()->sent_at)->not->toBeNull();
});

test('a failing send stays queued between retries and is marked failed once retries run out', function () {
    // Cloud API with no credentials: the driver throws, the service records failure.
    config(['services.messaging.whatsapp_driver' => 'whatsapp_cloud', 'services.whatsapp_cloud.token' => null]);
    Queue::fake();

    $c = queuedMessagingOrder();
    $c['order']->update(['status' => 'confirmed']);
    $message = DeliveryMessage::where('order_id', $c['order']->id)->where('recipient_type', 'customer')->sole();
    $service = app(\App\Services\Messaging\MessagingService::class);

    // First attempt fails: thrown so the queue retries, row left 'queued'.
    $first = (new SendDeliveryMessageJob($message->id))->withFakeQueueInteractions();
    expect(fn () => $first->handle($service))->toThrow(RuntimeException::class);
    expect($message->fresh()->status)->toBe('queued');

    // Last attempt fails: the worker then calls failed(), which settles the row.
    $last = (new SendDeliveryMessageJob($message->id))->withFakeQueueInteractions();
    $last->job->attempts = 3;

    try {
        $last->handle($service);
    } catch (RuntimeException $e) {
        $last->failed($e);
    }

    expect($message->fresh()->status)->toBe('failed');
});

test('the job gives up after three failed sends, with backoff — throttle waits are not failures', function () {
    $job = new SendDeliveryMessageJob(1);

    expect($job->maxExceptions)->toBe(3)
        ->and(SendDeliveryMessageJob::MAX_SEND_FAILURES)->toBe(3)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->backoff())->toBe([10, 60, 300])
        ->and($job->queue)->toBe('messaging')
        ->and($job->afterCommit)->toBeTrue();
});

test('the scheduled worker drains the messaging queue', function () {
    // A job on a queue nobody drains never sends. This guards the worker line
    // in bootstrap/app.php against losing 'messaging' or a WhatsApp lane —
    // and keeps P1 ahead of the rest.
    Artisan::call('schedule:list');
    $worker = collect(preg_split('/\R/', Artisan::output()))
        ->first(fn (string $line) => str_contains($line, 'queue:work'));

    expect($worker)->not->toBeNull()
        ->and($worker)->toContain('--queue=meta,messaging-p1,messaging,messaging-p2,messaging-p3,default');
});

test('a manual Send Message from the order page still sends synchronously', function () {
    config(['services.messaging.whatsapp_driver' => 'log_null']);
    Queue::fake();

    $c = queuedMessagingOrder('confirmed');

    test()->actingAs($c['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($c['vendor']);

    Livewire::test(ViewOrder::class, ['record' => $c['order']->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData(['recipient_type' => 'customer', 'channel' => 'whatsapp', 'body' => 'Hand-written note'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $message = DeliveryMessage::where('order_id', $c['order']->id)->where('body', 'Hand-written note')->sole();

    expect($message->status)->toBe('sent');
    Queue::assertNotPushed(SendDeliveryMessageJob::class, fn ($job) => $job->deliveryMessageId === $message->id);
});

test('the admin panel shows a banner while every message is being redirected', function () {
    config(['services.messaging.redirect_all_to' => '08099998888']);

    $admin = User::factory()->create();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    test()->actingAs($admin)->get('/admin')
        ->assertOk()
        ->assertSee('Messaging is in test mode')
        // The one-line version a phone shows until tapped.
        ->assertSee('messages go to')
        ->assertSee('8888')
        ->assertDontSee('08099998888');
});

test('no banner when redirect is off', function () {
    config(['services.messaging.redirect_all_to' => null]);

    $admin = User::factory()->create();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    test()->actingAs($admin)->get('/admin')
        ->assertOk()
        ->assertDontSee('Messaging is in test mode');
});
