<?php

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Filament\Vendor\Resources\Products\Pages\ViewProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function setUpCostPriceVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Cost Price Test Store']);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Cost Price Category']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Costed Widget',
        'sku'            => 'CW-001',
        'price'          => 5000,
        'cost_price'     => 3850,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function actAsStorekeeperFor(array $data): User
{
    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    test()->actingAs($storekeeper);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    return $storekeeper;
}

function actAsOwnerFor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('a storekeeper does not see cost price on the products list in table mode', function () {
    $data = setUpCostPriceVendor();
    actAsStorekeeperFor($data);

    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertSee('Costed Widget')
        ->assertDontSee('3,850.00');
});

test('a storekeeper does not see cost price on the products list in grid mode', function () {
    $data = setUpCostPriceVendor();
    actAsStorekeeperFor($data);

    Livewire::test(ListProducts::class, ['displayMode' => 'grid'])
        ->assertOk()
        ->assertSee('Costed Widget')
        ->assertDontSee('3,850.00');
});

test('the vendor owner still sees cost price on the products list', function () {
    $data = setUpCostPriceVendor();
    actAsOwnerFor($data);

    Livewire::test(ListProducts::class)->assertSee('3,850.00');
});

test('a storekeeper sees the selling price but not cost, profit, margin, or markup on the product view page', function () {
    $data = setUpCostPriceVendor();
    actAsStorekeeperFor($data);

    Livewire::test(ViewProduct::class, ['record' => $data['product']->getRouteKey()])
        ->assertOk()
        ->assertSee('₦5,000.00')
        ->assertDontSee('₦3,850.00')
        ->assertDontSee('Cost price')
        ->assertDontSee('Profit / unit')
        ->assertDontSee('Markup');
});

test('a role explicitly granted view_cost_price can see it on the product view page', function () {
    $data = setUpCostPriceVendor();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('product_manager');

    Role::where(['name' => 'product_manager', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('view_cost_price');

    $this->actingAs($manager);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(ViewProduct::class, ['record' => $data['product']->getRouteKey()])
        ->assertSee('₦3,850.00')
        ->assertSee('Cost price');
});

test('the cost price field and margin preview are hidden on the edit form for a role without view_cost_price', function () {
    $data = setUpCostPriceVendor();

    // product_manager has edit_products by default, but not view_cost_price —
    // the new permission is opt-in even for roles that already manage products.
    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('product_manager');

    $this->actingAs($manager);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->assertOk()
        ->assertDontSee('Leave blank if unknown')
        ->assertDontSee('Margin / Markup');
});

test('a role without view_cost_price can create a new product with only a selling price', function () {
    $data = setUpCostPriceVendor();

    // product_manager has create_products by default, but not view_cost_price —
    // the cost_price field never renders for them, so it's never submitted at
    // all (unlike editing an existing product, where the hidden field still
    // carries the record's already-set value through).
    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('product_manager');

    $this->actingAs($manager);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'category_id' => $data['category']->id,
            'name'        => 'No Cost Data Widget',
            'price'       => 4500,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'No Cost Data Widget')->firstOrFail();
    expect((float) $product->price)->toBe(4500.0)
        ->and($product->cost_price)->toBeNull();
});

test('saving the edit form without cost-price access does not wipe the existing cost price', function () {
    $data = setUpCostPriceVendor();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('product_manager');

    $this->actingAs($manager);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->fillForm(['name' => 'Renamed Widget'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $data['product']->fresh()->cost_price)->toBe(3850.0)
        ->and($data['product']->fresh()->name)->toBe('Renamed Widget');
});
