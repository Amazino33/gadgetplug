<?php

use App\Filament\Vendor\Pages\StoreProfile;
use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Products\SkuGenerator;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function styleShop(array $vendorAttributes = []): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(array_merge(['user_id' => $owner->id, 'name' => 'Style Shop '.uniqid()], $vendorAttributes));
    VendorRoles::seedFor($vendor);

    return [$owner, $vendor];
}

function styleProduct(Vendor $vendor, array $attributes = []): Product
{
    return Product::create(array_merge([
        'vendor_id'   => $vendor->id,
        'category_id' => Category::firstOrCreate(['name' => 'Alcohol'])->id,
        'name'        => 'Castile lite',
        'price'       => 10100,
        'status'      => 'published',
    ], $attributes));
}

function styleAs(User $user, Vendor $vendor): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

test('every shop stays on the category + name style until it picks another', function () {
    [, $vendor] = styleShop();

    expect($vendor->fresh()->sku_style)->toBe('category_name')
        ->and(styleProduct($vendor)->sku)->toMatch('/^ALC-CASTLI-\d{4}$/');
});

test('the serial style continues from the highest number the shop already holds', function () {
    [, $vendor] = styleShop(['sku_style' => 'serial']);

    foreach (['1', '2', '8225'] as $i => $sku) {
        styleProduct($vendor, ['name' => "Old {$i}", 'sku' => $sku]);
    }

    // Not numbers, so not part of the sequence.
    styleProduct($vendor, ['name' => 'Lettered', 'sku' => 'ABC-99999']);
    styleProduct($vendor, ['name' => 'Mixed', 'sku' => '90000X']);

    $next = styleProduct($vendor, ['name' => 'New one']);
    $then = styleProduct($vendor, ['name' => 'Newer one']);

    expect($next->sku)->toBe('8226')
        ->and($then->sku)->toBe('8227');
});

test('the serial style starts at 1 for a shop with no numbers yet', function () {
    [, $vendor] = styleShop(['sku_style' => 'serial']);

    expect(styleProduct($vendor)->sku)->toBe('1');
});

test('another shop\'s numbers do not move this shop\'s sequence', function () {
    [, $one] = styleShop(['sku_style' => 'serial']);
    [, $two] = styleShop(['sku_style' => 'serial']);

    styleProduct($two, ['sku' => '5000']);

    expect(styleProduct($one)->sku)->toBe('1');
});

test('the prefix style counts only SKUs that carry its prefix', function () {
    [, $vendor] = styleShop(['sku_style' => 'prefix_serial', 'sku_prefix' => 'VWD']);

    styleProduct($vendor, ['name' => 'A', 'sku' => 'VWD-10']);
    styleProduct($vendor, ['name' => 'B', 'sku' => 'VWD-ABC']);
    styleProduct($vendor, ['name' => 'C', 'sku' => '9999']);

    expect(styleProduct($vendor, ['name' => 'D'])->sku)->toBe('VWD-11');
});

test('changing style leaves existing SKUs alone and only applies to what comes next', function () {
    [, $vendor] = styleShop();

    $old = styleProduct($vendor);

    $vendor->update(['sku_style' => 'serial']);

    expect($old->fresh()->sku)->toMatch('/^ALC-CASTLI-\d{4}$/')
        ->and(styleProduct($vendor, ['name' => 'After'])->sku)->toBe('1');
});

test('a unit SKU is the product SKU plus a suffix, whatever the style', function () {
    [, $vendor] = styleShop(['sku_style' => 'serial']);
    $product = styleProduct($vendor, ['sku' => '8225']);

    $crate = ProductUnit::create(['product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 1000]);

    expect($crate->sku)->toBe('8225-CR12');
});

// ── Manual ──────────────────────────────────────────────────────────────────

test('a shop that types its own SKUs cannot save a product without one', function () {
    [, $vendor] = styleShop(['sku_style' => 'manual']);

    expect(fn () => styleProduct($vendor))->toThrow(ValidationException::class);

    expect(styleProduct($vendor, ['sku' => 'MINE-1'])->sku)->toBe('MINE-1');
});

test('the create form requires a SKU for a manual shop and keeps the one typed', function () {
    [$owner, $vendor] = styleShop(['sku_style' => 'manual']);
    styleAs($owner, $vendor);
    $category = Category::create(['name' => 'Alcohol']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Castile lite', 'category_id' => $category->id, 'price' => 500, 'status' => 'draft', 'sku' => ''])
        ->call('create')
        ->assertHasFormErrors(['sku' => 'required']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Castile lite', 'category_id' => $category->id, 'price' => 500, 'status' => 'draft', 'sku' => 'TYPED-1'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('name', 'Castile lite')->value('sku'))->toBe('TYPED-1');
});

test('the backfill command leaves manual shops alone', function () {
    [, $manual] = styleShop(['sku_style' => 'manual']);
    [, $auto]   = styleShop();

    $a = styleProduct($manual, ['sku' => 'X']);
    $b = styleProduct($auto);
    DB::table('products')->whereIn('id', [$a->id, $b->id])->update(['sku' => null]);

    $this->artisan('products:backfill-sku')->assertSuccessful();

    expect($a->fresh()->sku)->toBeNull()
        ->and($b->fresh()->sku)->not->toBeNull();
});

// ── Store Profile ───────────────────────────────────────────────────────────

test('the owner picks the SKU style in Store Profile', function () {
    [$owner, $vendor] = styleShop();
    styleAs($owner, $vendor);

    Livewire::test(StoreProfile::class)
        ->assertFormSet(['sku_style' => 'category_name'])
        ->fillForm(['sku_style' => 'prefix_serial', 'sku_prefix' => 'vwd'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($vendor->fresh()->sku_style)->toBe('prefix_serial')
        ->and($vendor->fresh()->sku_prefix)->toBe('VWD');

    Livewire::test(StoreProfile::class)
        ->fillForm(['sku_style' => 'serial'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The prefix goes with the style that used it.
    expect($vendor->fresh()->sku_style)->toBe('serial')
        ->and($vendor->fresh()->sku_prefix)->toBeNull();
});

test('the prefix style needs a prefix', function () {
    [$owner, $vendor] = styleShop();
    styleAs($owner, $vendor);

    Livewire::test(StoreProfile::class)
        ->fillForm(['sku_style' => 'prefix_serial', 'sku_prefix' => ''])
        ->call('save')
        ->assertHasFormErrors(['sku_prefix' => 'required']);
});

test('a team member who can edit the store profile still cannot change the SKU style', function () {
    [, $vendor] = styleShop();

    $admin = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $admin->assignRole('store_admin');

    styleAs($admin, $vendor);

    expect($admin->hasVendorPermission($vendor->id, 'edit_vendor'))->toBeTrue();

    Livewire::test(StoreProfile::class)
        ->fillForm(['sku_style' => 'manual', 'description' => 'changed'])
        ->call('save');

    $fresh = $vendor->fresh();

    expect($fresh->sku_style)->toBe('category_name')
        ->and($fresh->description)->toBe('changed');
});
