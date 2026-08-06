<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeRateOverrideProduct(): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Rate Override Store ' . uniqid()]);
    $category = Category::create(['name' => 'Rate Override Category ' . uniqid()]);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Rate Override Product',
        'price'          => 3000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);
}

test('a super admin can access the affiliate product rate resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    expect(ProductResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the affiliate product rate resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Rate Vendor']);

    $this->actingAs($owner);

    expect(ProductResource::canAccess())->toBeFalse();
});

test('the affiliate product rate resource cannot create or delete', function () {
    expect(ProductResource::canCreate())->toBeFalse()
        ->and(ProductResource::canDelete(new Product()))->toBeFalse();
});

test('a super admin can set a commission rate override on a product', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    $product = makeRateOverrideProduct();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['commission_rate' => 8.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $product->fresh()->commission_rate)->toBe(8.5);
});

test('a super admin can set a reseller discount override on a product', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    $product = makeRateOverrideProduct();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['reseller_discount' => 15.0])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $product->fresh()->reseller_discount)->toBe(15.0);
});
