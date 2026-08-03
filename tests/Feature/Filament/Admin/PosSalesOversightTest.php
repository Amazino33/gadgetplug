<?php

use App\Filament\Resources\PosSales\PosSaleResource;
use App\Filament\Resources\PosSales\Pages\ListPosSales;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makePosSaleForAdminTest(Vendor $vendor, string $reference): PosSale
{
    $category = Category::create(['name' => 'Admin POS Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Admin POS Product',
        'price'          => 4500,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $sale = PosSale::create([
        'reference'       => $reference,
        'vendor_id'       => $vendor->id,
        'cashier_id'      => $vendor->user_id,
        'subtotal'        => 4500,
        'vat_amount'      => 0,
        'total'           => 4500,
        'payment_method'  => 'cash',
        'amount_tendered' => 4500,
        'status'          => 'completed',
        'synced'          => true,
        'completed_at'    => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $product->id,
        'product_name' => $product->name,
        'unit_price'   => 4500,
        'quantity'     => 1,
        'total'        => 4500,
    ]);

    return $sale;
}

test('a super admin can access the admin POS sales resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    expect(PosSaleResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the admin POS sales resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular POS Vendor']);

    $this->actingAs($owner);

    expect(PosSaleResource::canAccess())->toBeFalse();
});

test('the admin POS sales list shows sales from every vendor with their store name', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $ownerA  = User::factory()->create();
    $vendorA = Vendor::create(['user_id' => $ownerA->id, 'name' => 'Alpha POS Store']);
    $ownerB  = User::factory()->create();
    $vendorB = Vendor::create(['user_id' => $ownerB->id, 'name' => 'Beta POS Store']);

    makePosSaleForAdminTest($vendorA, 'POS-ALPHA-' . uniqid());
    makePosSaleForAdminTest($vendorB, 'POS-BETA-' . uniqid());

    $this->actingAs($admin);

    Livewire::test(ListPosSales::class)
        ->assertSee('Alpha POS Store')
        ->assertSee('Beta POS Store');
});

test('the admin POS sales resource is read-only', function () {
    expect(PosSaleResource::canCreate())->toBeFalse()
        ->and(PosSaleResource::canEdit(new PosSale()))->toBeFalse()
        ->and(PosSaleResource::canDelete(new PosSale()))->toBeFalse();
});
