<?php

use App\Filament\Resources\AffiliateCommissions\AffiliateCommissionResource;
use App\Filament\Resources\AffiliateCommissions\Pages\ListAffiliateCommissions;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
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

function makeCommissionForOversightTest(string $status): AffiliateCommission
{
    $owner    = User::factory()->create();
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Oversight Store ' . uniqid()]);
    $category = Category::create(['name' => 'Oversight Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Oversight Product',
        'price'          => 2000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'GP-' . uniqid(),
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => 2000,
        'status'           => 'pending',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $product->vendor_id,
        'quantity'   => 1,
        'unit_price' => 2000,
    ]);

    return AffiliateCommission::create([
        'affiliate_id' => $affiliate->id,
        'order_id'     => $order->id,
        'amount'       => 100,
        'status'       => $status,
    ]);
}

test('a super admin can access the commission oversight resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    expect(AffiliateCommissionResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the commission oversight resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Commission Vendor']);

    $this->actingAs($owner);

    expect(AffiliateCommissionResource::canAccess())->toBeFalse();
});

test('the commission oversight resource is read-only', function () {
    expect(AffiliateCommissionResource::canCreate())->toBeFalse()
        ->and(AffiliateCommissionResource::canEdit(new AffiliateCommission()))->toBeFalse()
        ->and(AffiliateCommissionResource::canDelete(new AffiliateCommission()))->toBeFalse();
});

test('the commission list shows commissions across every affiliate', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    $available = makeCommissionForOversightTest('available');
    $pending   = makeCommissionForOversightTest('pending');

    Livewire::test(ListAffiliateCommissions::class)
        ->assertSee($available->affiliate->code)
        ->assertSee($pending->affiliate->code);
});
