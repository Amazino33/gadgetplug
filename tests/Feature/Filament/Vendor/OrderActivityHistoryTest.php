<?php

use App\Filament\Vendor\Resources\OrderItems\Pages\ViewOrderItem;
use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function setUpActivityHistoryVendor(): array
{
    $owner    = User::factory()->create(['name' => 'Jane Owner']);
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Activity History Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Activity History Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Activity History Product',
        'price'          => 4000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'ORD-ACTIVITY-' . uniqid(),
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 4000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    $item = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 4000,
    ]);

    return compact('owner', 'vendor', 'order', 'item');
}

function actAsActivityHistoryVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('a status change records the authenticated user as the causer', function () {
    $data = setUpActivityHistoryVendor();
    actAsActivityHistoryVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $activity = Activity::where('subject_type', Order::class)
        ->where('subject_id', $data['order']->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($data['owner']->id)
        ->and($activity->causer_type)->toBe(User::class);
});

test('activities are ordered most-recent first', function () {
    $data = setUpActivityHistoryVendor();
    actAsActivityHistoryVendor($data);

    $data['order']->update(['status' => 'shipped']);
    $data['order']->update(['status' => 'delivered']);

    $statuses = $data['order']->fresh()->activities->map(
        fn ($a) => $a->changes()['attributes']['status'] ?? null
    )->filter()->values()->all();

    expect($statuses)->toBe(['delivered', 'shipped', 'paid']);
});

test('the order view page shows who changed the status in the activity history', function () {
    $data = setUpActivityHistoryVendor();
    actAsActivityHistoryVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => false])
        ->callMountedAction();

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSee('Jane Owner')
        ->assertSee('Status changed to Shipped');
});

test('the order item view page also shows who changed the status', function () {
    $data = setUpActivityHistoryVendor();
    actAsActivityHistoryVendor($data);

    $data['order']->update(['status' => 'shipped']);

    Livewire::test(ViewOrderItem::class, ['record' => $data['item']->getRouteKey()])
        ->assertSee('Jane Owner')
        ->assertSee('Status changed to Shipped');
});
