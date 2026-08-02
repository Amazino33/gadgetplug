<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeOrderForVendor(Vendor $vendor, string $reference): Order
{
    $category = Category::create(['name' => 'Oversight Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Oversight Product',
        'price'          => 4000,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => $reference,
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 4000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 4000,
    ]);

    return $order;
}

test('a super admin can access the admin orders resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    expect(OrderResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the admin orders resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Vendor']);

    $this->actingAs($owner);

    expect(OrderResource::canAccess())->toBeFalse();
});

test('the admin orders list shows orders from every vendor with their store name', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $ownerA = User::factory()->create();
    $vendorA = Vendor::create(['user_id' => $ownerA->id, 'name' => 'Alpha Store']);
    $ownerB = User::factory()->create();
    $vendorB = Vendor::create(['user_id' => $ownerB->id, 'name' => 'Beta Store']);

    makeOrderForVendor($vendorA, 'ORD-ALPHA-' . uniqid());
    makeOrderForVendor($vendorB, 'ORD-BETA-' . uniqid());

    $this->actingAs($admin);

    Livewire::test(ListOrders::class)
        ->assertSee('Alpha Store')
        ->assertSee('Beta Store');
});

test('the admin orders resource has no create, edit, or delete capability', function () {
    expect(OrderResource::canCreate())->toBeFalse()
        ->and(OrderResource::canEdit(new Order()))->toBeFalse()
        ->and(OrderResource::canDelete(new Order()))->toBeFalse();
});
