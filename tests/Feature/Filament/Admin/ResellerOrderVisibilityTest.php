<?php

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Affiliate;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Affiliate\ResellerCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForResellerOrders(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

function makeResellerOrderForVisibilityTest(): \App\Models\Order
{
    $user = User::factory()->create(['phone' => '08040000000']);
    $affiliate = Affiliate::findOrCreateForUser($user);

    WalletTransaction::create([
        'affiliate_id' => $affiliate->id,
        'type'         => 'credit',
        'amount'       => 5000,
        'description'  => 'Seed credit',
    ]);

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Reseller Visibility Store ' . uniqid()]);
    $category = Category::create(['name' => 'Reseller Visibility Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Reseller Visibility Product',
        'price'          => 2000,
        'reseller_discount' => 25.0,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    return app(ResellerCheckoutService::class)->purchase(
        $affiliate,
        [['product' => $product, 'quantity' => 1]],
        'Uyo, Akwa Ibom State — Visibility Test Address',
    );
}

test('the admin orders list labels a reseller order as Reseller (Wallet), not Paystack', function () {
    $this->actingAs(actingAsSuperAdminForResellerOrders());

    makeResellerOrderForVisibilityTest();

    Livewire::test(ListOrders::class)
        ->assertSee('Reseller (Wallet)');
});

test('the payment filter can isolate reseller orders from the admin order list', function () {
    $this->actingAs(actingAsSuperAdminForResellerOrders());

    $resellerOrder = makeResellerOrderForVisibilityTest();

    Livewire::test(ListOrders::class)
        ->filterTable('payment_method', 'wallet')
        ->assertCanSeeTableRecords([$resellerOrder]);
});

test('the order detail page shows the reseller affiliate and the exact wallet debit for a reseller order', function () {
    $this->actingAs(actingAsSuperAdminForResellerOrders());

    $order = makeResellerOrderForVisibilityTest();

    // 25% off 2000 = 1500
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('₦1,500.00');
});

test('the reseller purchase section is hidden on a normal Paystack order', function () {
    $this->actingAs(actingAsSuperAdminForResellerOrders());

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Normal Order Store']);
    $category = Category::create(['name' => 'Normal Order Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Normal Order Product',
        'price'          => 1000,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $order = \App\Models\Order::create([
        'reference'        => 'GP-NORMAL-' . uniqid(),
        'customer_name'    => 'Normal Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo, Akwa Ibom State — Normal Address',
        'total_amount'     => 1000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    \App\Models\OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertDontSee('Wallet Debited');
});
